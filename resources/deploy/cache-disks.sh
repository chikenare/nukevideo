# The edge cache disks of a proxy node: finding them, formatting them and mounting them.
# Sourced by the panel's disk listing (inventory only) and by the proxy deploy (the lot).
# See App\Services\ProxyCacheService for the reasoning; the constants there mirror these.

CACHE_POOL_MOUNT=/var/lib/nukevideo/cache
# The filesystem label. XFS takes twelve characters and mkfs.xfs refuses a longer one outright,
# so it cannot be the array's name.
CACHE_POOL_LABEL=nukevideo
CACHE_POOL_MD=/dev/md/nukevideo-cache
CACHE_POOL_NAME=${CACHE_POOL_MD##*/}
# A pool that mixes spinning disks with SSD/NVMe is built in two tiers (see cache_create_pool):
# the spinning ones in CACHE_POOL_MD for capacity, the fast ones in their own array, and LVM's
# dm-cache putting the second in front of the first. Every name below is how a redeploy finds
# a tiered pool again; a pool of one kind of disk uses none of them.
CACHE_FAST_MD=/dev/md/nukevideo-fast
CACHE_FAST_NAME=${CACHE_FAST_MD##*/}
CACHE_VG=nukevideo_edge
CACHE_LV=data
CACHE_LV_DEVICE=/dev/$CACHE_VG/$CACHE_LV
# Written at the root of the node's cache directory on the pool; the edge's entrypoint looks for
# it to tell a mounted pool from the bare mount point directory on the OS disk (`nofail` lets
# the host boot without the pool, and the directory is then just a directory).
CACHE_POOL_MARKER=.nukevideo-pool
# The least unpartitioned space on a system disk worth offering. The edge keeps a 20 GB reserve
# and only uses half of a filesystem under twice that ({@see vod/entrypoint.sh}), so a smaller
# partition would cache little more than the fallback volume while costing the OS disk every
# byte it could still have grown into.
CACHE_SHARED_MIN_BYTES=$((50 * 1024 * 1024 * 1024))

# The largest unpartitioned region of a disk as `start sectors`, in the disk's own logical
# sectors and aligned by sfdisk already; nothing when it has no room or is not GPT. Reads the
# raw device, so it needs root like blkid below.
#
# GPT only, on purpose. An MBR table reports the inside of an extended partition as free, has
# four primary slots to run out of, and gives an entry no name to recognise a half-finished
# deploy by: three ways for a deploy to stop halfway on the one disk it must not get wrong.
# Anything a cache is worth carving from — any disk past 2 TiB, any UEFI install — is GPT.
cache_free_region() {
    [ "$($SUDO blkid -p -o value -s PTTYPE "$1" 2>/dev/null)" = "gpt" ] || return 0
    $SUDO sfdisk -F "$1" 2>/dev/null \
        | awk '$1 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ && $3 > max { max = $3; start = $1 } END { if (max) print start, max }'
}

# FSTYPE and LABEL of a device as the device itself answers, not as udev last recorded them,
# which is what lsblk prints. udev drops its watch on a device while it handles an event for
# it, so the second of two writes in a row — a wipefs and the mdadm that follows, which is
# this very deploy — can go unrecorded until the next boot, and every decision here is about
# what is on a disk now. Needs root.
cache_probe() {
    FSTYPE=$($SUDO blkid -p -o value -s TYPE "$1" 2>/dev/null) || true
    LABEL=""
    [ -z "$FSTYPE" ] || LABEL=$($SUDO blkid -p -o value -s LABEL "$1" 2>/dev/null) || true
}

# A partition an earlier deploy carved out of a system disk and never got to format, as
# `device bytes`: ours by the name it was given, and still blank. Without this a deploy that
# died between the two steps would leave the disk with no free space and nothing to offer —
# plain `system` forever. The name is the only evidence taken: guessing that an unsigned
# partition is ours is not an option.
cache_carved_partition() {
    local part type size
    lsblk -pnr --bytes -o KNAME,TYPE,SIZE "$1" 2>/dev/null | while read -r part type size; do
        [ "$type" = "part" ] || continue
        [ "$($SUDO blkid -p -o value -s PART_ENTRY_NAME "$part" 2>/dev/null)" = "$CACHE_POOL_NAME" ] || continue
        cache_probe "$part"
        [ -n "$FSTYPE" ] || echo "$part $size"
    done | head -n1
}

# One line per whole disk on the host: `device<TAB>size<TAB>model<TAB>state<TAB>detail<TAB>free`.
# The state is the whole decision:
#   system    — something under it is mounted (other than our pool) or is swap. Untouchable.
#   shared    — a system disk with unpartitioned space to spare, `free` bytes of it: a host
#               whose only disks are the ones the OS sits on. What exists is as untouchable as
#               on `system`; a deploy only adds a partition over that space.
#   nukevideo — carries this cache already: a member of our array or our labelled XFS, on the
#               whole disk or on a partition carved next to the OS.
#   empty     — no filesystem, partition table or RAID signature anywhere on it.
#   foreign   — has data of some other origin. Wiped on deploy, which is what the panel warns about.
cache_disk_inventory() {
    # `-P` prints `KEY="value"` pairs with every unsafe byte hex-escaped by lsblk itself, which
    # is what makes the `eval` safe and the only way to keep an empty field (a partition with
    # no label) from being swallowed by `read`.
    lsblk -dn -P --bytes -o NAME,TYPE,RM,SIZE,MODEL 2>/dev/null | while IFS= read -r disk; do
        eval "$disk"
        # Whole disks only. zram, loop, optical and floppy devices are `disk` to lsblk too, and
        # removable media (a USB stick left in a server) is not where a cache belongs.
        [ "$TYPE" = "disk" ] && [ "$RM" = "0" ] || continue
        case "$NAME" in zram*|loop*|sr*|fd*|nbd*) continue ;; esac
        [ "$SIZE" -gt 0 ] 2>/dev/null || continue

        dev="/dev/$NAME"
        state="empty"
        detail=""
        free=0
        system=""
        pool=""
        foreign=""

        # The subtree: partitions, and the md/LVM volumes assembled from them, with whatever is
        # mounted on any of those. That is what makes the OS disks visible as such even when /
        # sits on an md mirror or an LVM volume two layers down.
        while IFS= read -r part; do
            [ -n "$part" ] || continue
            eval "$part"
            MOUNTPOINT=$(printf '%b' "$MOUNTPOINT")
            cache_probe "$KNAME"
            # The whole subtree is read, with no early exit on the first mountpoint: the cache
            # may sit on a partition of the very disk that holds the OS, and stopping at `/`
            # would hide the pool from the redeploy meant to keep it.
            if [ -n "$MOUNTPOINT" ] && [ "$MOUNTPOINT" != "$CACHE_POOL_MOUNT" ] && [ "$MOUNTPOINT" != "[SWAP]" ]; then
                system=${system:-"mounted at $MOUNTPOINT"}
            elif [ "$FSTYPE" = "swap" ] || [ "$MOUNTPOINT" = "[SWAP]" ]; then
                system=${system:-swap}
            fi
            # mdadm stores the array name as `{homehost}:{name}`, hence the suffix match.
            if [ "$FSTYPE" = "xfs" ] && [ "$LABEL" = "$CACHE_POOL_LABEL" ]; then
                pool="nukevideo cache (xfs)"
            elif [ "$FSTYPE" = "linux_raid_member" ] && [[ "$LABEL" == *"$CACHE_POOL_NAME" ]]; then
                pool="nukevideo cache (raid member)"
            elif [ "$FSTYPE" = "linux_raid_member" ] && [[ "$LABEL" == *"$CACHE_FAST_NAME" ]]; then
                pool="nukevideo cache (fast tier, raid member)"
            elif [ "$FSTYPE" = "LVM2_member" ] && cache_in_vg "$KNAME"; then
                pool="nukevideo cache (tier)"
            elif [ "$FSTYPE" = "LVM2_member" ] && [ "$(cache_vg_of "$KNAME")" = "?" ]; then
                # A PV nobody could read the group of is somebody's data until shown otherwise:
                # `foreign` is wiped when ticked, and this may be a tier of this very cache.
                system=${system:-"an LVM member whose volume group could not be read"}
            elif [ -z "$foreign" ] && [ -n "$FSTYPE" ]; then
                foreign="$FSTYPE${LABEL:+ \"$LABEL\"}"
            fi
        done <<< "$(lsblk -pn -P -o KNAME,MOUNTPOINT "$dev" 2>/dev/null)"

        # A disk carrying the pool is the pool's whatever else is on it, and none of the states
        # that get written to: neither `nukevideo` nor `system` is ever formatted or carved.
        if [ -n "$pool" ]; then
            state="nukevideo"; detail="$pool${system:+, beside the system}"
        elif [ -n "$system" ]; then
            state="system"; detail="$system"
            # Only a disk that is partitioned as itself has space to give. One that is whole a
            # member of something — a firmware or md mirror with its metadata at the end, a
            # multipath leg — shows the volume's partition table through the raw device, and
            # a partition written there would land inside the volume, on one leg of it.
            # A check that could not run is not a disk that passed it: the holders directory
            # has to be there to be found empty, and {@see cache_free_region} offers nothing
            # from a disk blkid did not positively call GPT.
            cache_probe "$dev"
            if [ -z "$FSTYPE" ] && [ -d "/sys/block/$NAME/holders" ] && [ -z "$(ls -A "/sys/block/$NAME/holders")" ]; then
                read -r _ free <<< "$(cache_carved_partition "$dev")"
                if [ -z "$free" ]; then
                    read -r _ sectors <<< "$(cache_free_region "$dev")"
                    free=$(( ${sectors:-0} * $(cat "/sys/block/$NAME/queue/logical_block_size" 2>/dev/null || echo 512) ))
                fi
                # Said rather than passed off as "no room": it is still missing after the
                # listing tried to install it, and the panel shows this to whoever is wondering
                # where the disk went.
                $SUDO sfdisk --version &>/dev/null || detail="$detail (sfdisk missing)"
            fi
            if [ "$free" -ge "$CACHE_SHARED_MIN_BYTES" ]; then
                state="shared"; detail="$system, with unpartitioned space"
            else
                free=0
            fi
        elif [ -n "$foreign" ]; then
            state="foreign"; detail="$foreign"
        else
            # No filesystem anywhere is not no data: a partitioned disk with nothing on its
            # partitions still has a table. Needs root to read the raw device — without it
            # such a disk passes for an empty one.
            sig=$($SUDO blkid -p -o value -s PTTYPE -s TYPE "$dev" 2>/dev/null | head -n1)
            [ -n "$sig" ] && { state="foreign"; detail="$sig partition table"; }
        fi

        # `free` goes last and is never blank, so the line cannot lose its tail to a trim.
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$dev" "$SIZE" "$(printf '%b' "$MODEL")" "$state" "$detail" "$free"
    done
}

# What the deploy may write to: every `empty`/`foreign` disk, formatted whole (FREE_DISKS), and
# every `shared` one, which only gets a partition over its unpartitioned space (SHARED_DISKS).
# The panel sends one list for each — CHOSEN_DISKS and CHOSEN_SHARED — because a device name
# alone does not say which of the two was agreed to, and the state is read again here, later
# than the panel read it: a disk ticked as shared whose filesystem has since been unmounted
# is `foreign` now, and a single list would hand it to the wipe. A disk the operator did not
# tick is reported and left exactly as it is, and a system disk with no room to spare is out
# before any list applies.
#
# No CHOSEN_DISKS at all means every spare disk. Nothing means "every shared disk": putting
# the cache on the disk the OS runs from is only ever done because somebody asked for that
# disk by name.
cache_select_disks() {
    local d skipped
    FREE_DISKS=$(echo "$INVENTORY" | awk -F'\t' '$4 == "empty" || $4 == "foreign" { print $1 }')
    SHARED_DISKS=$(echo "$INVENTORY" | awk -F'\t' '$4 == "shared" { print $1 }')

    # Refused outright rather than skipped: what was asked for cannot be done, and the one
    # thing worse than not sharing the disk is doing something else to it.
    for d in ${CHOSEN_SHARED:-}; do
        # A disk already carrying the pool is what asking for it was meant to achieve.
        case " ${SHARED_DISKS//$'\n'/ } ${POOL_DISKS//$'\n'/ } " in *" $d "*) ;; *)
            echo "ERROR: $d was to be shared with the system, but it is no longer a system disk with unpartitioned space. Nothing was changed; reopen the deploy dialog and check the disks."
            exit 1 ;;
        esac
    done

    skipped=$(for d in $SHARED_DISKS; do case " ${CHOSEN_SHARED:-} " in *" $d "*) ;; *) echo "$d" ;; esac; done)
    SHARED_DISKS=$(for d in $SHARED_DISKS; do case " ${CHOSEN_SHARED:-} " in *" $d "*) echo "$d" ;; esac; done)
    if [ -n "${CHOSEN_DISKS+x}" ]; then
        skipped="$skipped $(for d in $FREE_DISKS; do case " $CHOSEN_DISKS " in *" $d "*) ;; *) echo "$d" ;; esac; done)"
        FREE_DISKS=$(for d in $FREE_DISKS; do case " $CHOSEN_DISKS " in *" $d "*) echo "$d" ;; esac; done)
    fi
    [ -n "${skipped// /}" ] && echo "Left untouched, not selected:" $skipped
    return 0
}

# Every signature off the chosen disks. A member of some other, still assembled array must be
# released before its superblock can go; `--stop` on a running array is refused, not forced.
cache_wipe_disks() {
    for d in $FREE_DISKS; do
        for md in $(lsblk -rn -o NAME,TYPE "$d" | awk '$2 ~ /raid/ { print "/dev/"$1 }' | sort -u); do
            $SUDO umount "$md" 2>/dev/null || true
            $SUDO mdadm --stop "$md" 2>/dev/null || true
        done
        $SUDO mdadm --zero-superblock --force "$d" 2>/dev/null || true
        $SUDO wipefs -a "$d" >/dev/null
    done
}

# One partition over the largest unpartitioned region of each chosen system disk, left in
# CARVED_PARTS. Appended to the table, so every existing entry — the OS — keeps its number,
# start and size; the worst a failure here leaves behind is an empty partition, which the next
# deploy picks up again.
cache_carve_partitions() {
    CARVED_PARTS=""
    local d name start sectors unit nr entry part
    for d in $SHARED_DISKS; do
        name=${d#/dev/}
        read -r part _ <<< "$(cache_carved_partition "$d")"

        if [ -n "$part" ]; then
            echo "Reusing $part, left unformatted by an earlier deploy"
        else
            read -r start sectors <<< "$(cache_free_region "$d")"
            [ -n "$sectors" ] || { echo "ERROR: no unpartitioned space left on $d"; exit 1; }

            # Named, which is what lets a later deploy pick it up again
            # ({@see cache_carved_partition}). `--no-reread` lifts sfdisk's refusal to write to
            # a disk with mounted partitions and nothing else. The kernel will not re-read the
            # table of a busy disk either, so it is not asked to. The type is GPT's "Linux
            # filesystem" spelled out: the `L` shortcut is refused in this syntax by the
            # util-linux of Ubuntu 20.04.
            echo "start=$start, size=$sectors, type=0FC63DAF-8483-4772-8E79-3D69D8477DE4, name=$CACHE_POOL_NAME" \
                | $SUDO sfdisk --append --no-reread --no-tell-kernel -q "$d"

            # The kernel is handed the one new entry and nothing else: a bare `partx -u` walks
            # the whole table, dropping and re-adding every partition that happens not to be
            # in use. `-u` rather than `-a` because udev may have got there first, and adding
            # an entry the kernel already has is an error. Found by where it starts, not by
            # guessing the number sfdisk gave it; partx and sysfs both count in 512-byte units
            # whatever the disk's sector size is.
            unit=$(( $(cat "/sys/block/$name/queue/logical_block_size") / 512 ))
            nr=$($SUDO partx -g -r -o NR,START "$d" | awk -v start="$((start * unit))" '$2 == start { print $1 }')
            [ -n "$nr" ] || { echo "ERROR: the partition written to $d is not in its table"; exit 1; }
            $SUDO partx -u --nr "$nr" "$d"
            $SUDO udevadm settle 2>/dev/null || true

            for entry in /sys/block/"$name"/*/start; do
                [ -e "$entry" ] && [ "$(cat "$entry")" = "$((start * unit))" ] && part="/dev/$(basename "$(dirname "$entry")")"
            done
        fi
        [ -b "$part" ] || { echo "ERROR: the partition created on $d never showed up"; exit 1; }

        # Whatever a previous life of the disk left in that region would otherwise be read back
        # as a filesystem by the next inventory.
        $SUDO wipefs -a "$part" >/dev/null
        CARVED_PARTS="${CARVED_PARTS:+$CARVED_PARTS }$part"
    done
}

# Whether a device is SSD/NVMe: what the kernel says of the disk it sits on, so a partition
# carved next to the OS is as fast as its disk.
cache_is_fast() {
    local queue
    queue=$(cache_queue_of "$(basename "$(readlink -f "$1")")")
    [ "$(cat "/sys/block/$queue/queue/rotational" 2>/dev/null)" = "0" ]
}

# Whether this kernel can run dm-cache. Stock distribution kernels ship it as a module LVM loads
# on demand; the stripped kernels some cloud images boot (Ubuntu's `linux-kvm`, say) do not
# ship it at all, and LVM would build the volumes and then fail to activate the cache.
cache_has_dm_cache() {
    $SUDO modprobe dm_cache 2>/dev/null || true
    $SUDO modprobe dm_cache_smq 2>/dev/null || true
    $SUDO dmsetup targets 2>/dev/null | grep -qw cache
}

# LVM asked without its devices file. Where one is in use (RHEL 9 and its kin) LVM only sees
# the disks it lists, and a pool disk missing from it — its ID changed, say — would read as a
# stranger's. Older LVM, which has no such file, warns about the setting and carries on.
cache_lvm() {
    $SUDO "$@" --config 'devices { use_devicesfile = 0 }' 2>/dev/null
}

# The volume group a physical volume belongs to: its name, nothing for a PV in none, and `?`
# when it could not be read — LVM missing, or the device not readable as a PV at all.
cache_vg_of() {
    local vg
    vg=$(cache_lvm pvs --noheadings -o vg_name "$1") || { echo "?"; return 0; }
    echo "$vg" | tr -d ' '
}

cache_in_vg() {
    [ "$(cache_vg_of "$1")" = "$CACHE_VG" ]
}

# One RAID 0 across the given devices, left in CACHE_ARRAY; a single device is left as it is,
# with no array. Never run inside `$(...)`, where `set -e` does not reach: a create that failed
# there went unnoticed and the next line formatted whatever already had that name. A new array
# is wiped once created — it sits where an earlier one may have, at the same offsets — and
# replaces its own line in mdadm.conf, which a rebuilt pool would otherwise leave pointing at
# the old one. 512k chunks: a segment is megabytes, so a read still spans the members; md
# stripes unequal members by zones, so mixed sizes lose nothing.
cache_build_array() {
    local md=$1 name=$2
    shift 2
    if [ "$#" -eq 1 ]; then CACHE_ARRAY=$1; return 0; fi
    if ! $SUDO mdadm --create "$md" --level=0 --chunk=512K --metadata=1.2 --name="$name" \
        --raid-devices="$#" --run "$@" >/dev/null; then
        echo "ERROR: could not create $md over $* (see above). Nothing was formatted."
        exit 1
    fi
    $SUDO wipefs -a -q "$md" >/dev/null 2>&1 || true
    $SUDO mkdir -p /etc/mdadm
    $SUDO touch /etc/mdadm/mdadm.conf
    $SUDO sed -i "/[:=]$name\( \|\$\)/d" /etc/mdadm/mdadm.conf
    $SUDO mdadm --detail --scan | grep -F "$name" | $SUDO tee -a /etc/mdadm/mdadm.conf >/dev/null
    CACHE_ARRAY=$md
}

# `-K`: no discard of the whole device first. On a thin-provisioned virtual disk the TRIM runs at
# the backend's pace — 0.2 GB/s on an 11 TB volume, fourteen hours — and the SSH session of the
# deploy gives up long before; the blocks are about to be filled with cache anyway. No `-f`
# where this finishes an earlier deploy: mkfs.xfs then refuses a device it finds a filesystem
# on, so it cannot be what erases a cache blkid failed to see.
cache_format() {
    $SUDO mkfs.xfs -q -K -L "$CACHE_POOL_LABEL" "$@"
}

# The pool, across every chosen device, labelled so the next deploy recognises it.
#
# Devices of one kind — all spinning, or all SSD/NVMe — make one RAID 0 (plain XFS on a single
# one). A mix is not striped together: every read that spans members waits for the slowest, so
# an SSD in a RAID 0 of HDDs serves at HDD speed and its only contribution is capacity. It is
# built in two tiers instead: the spinning devices are the pool's capacity, the fast ones a
# dm-cache in front of them, which the kernel fills with the blocks read most — the segments
# being watched — so those are served from flash while the long tail stays on disk.
#
# The cache is writethrough: everything written lands on the spinning tier too, so the fast
# tier never holds the only copy of anything, and losing it loses speed, not the pool
# (cache_repair_tiers). Its capacity is not added to the pool's: it holds copies, which is what
# makes it fast. When the tiers cannot be built — no LVM tools, no dm-cache — every disk goes
# into the one RAID 0 instead: the disks were already wiped, and none is left out.
cache_create_pool() {
    local device fast="" bulk=""
    for device in $POOL_DEVICES; do
        if cache_is_fast "$device"; then fast="$fast $device"; else bulk="$bulk $device"; fi
    done

    if [ -n "$fast" ] && [ -n "$bulk" ]; then
        if ! ensure_commands lvcreate=lvm2 dmsetup=dmsetup cache_check=thin-provisioning-tools; then
            echo "WARNING: without the LVM tools the SSD/NVMe disks cannot cache the spinning ones — one pool of every disk instead"
            fast=""
        elif ! cache_has_dm_cache; then
            echo "WARNING: this kernel has no dm-cache — the SSD/NVMe disks join the spinning ones in a single pool instead of caching them"
            fast=""
        fi
    fi

    if [ -z "$fast" ] || [ -z "$bulk" ]; then
        echo "One pool across every disk ($(echo $POOL_DEVICES))"
        # shellcheck disable=SC2086
        cache_build_array "$CACHE_POOL_MD" "$CACHE_POOL_NAME" $POOL_DEVICES
        cache_format -f "$CACHE_ARRAY"
        return 0
    fi

    echo "Mixed disks: spinning ($(echo $bulk)) for capacity, SSD/NVMe ($(echo $fast)) as a cache in front"
    # shellcheck disable=SC2086
    cache_build_array "$CACHE_POOL_MD" "$CACHE_POOL_NAME" $bulk
    CACHE_BULK_DEVICE=$CACHE_ARRAY
    # shellcheck disable=SC2086
    cache_build_array "$CACHE_FAST_MD" "$CACHE_FAST_NAME" $fast
    CACHE_FAST_DEVICE=$CACHE_ARRAY
    cache_build_tiers
}

# A physical volume made of a device only when nothing else is on it. The devices here are
# ours and blank by construction — a new array, wiped; a whole disk or carved partition, wiped
# before the pool was built — and this is what keeps one misread device from being the
# exception.
cache_make_pv() {
    local found
    cache_lvm pvs "$1" >/dev/null && return 0
    # blkid exits non-zero for a device it finds nothing on — the expected case — which under
    # `set -e` would end the deploy on this assignment.
    found=$($SUDO blkid -p -o value -s TYPE "$1" 2>/dev/null || true)
    if [ -n "$found" ]; then
        echo "ERROR: $1 carries $found, which is not this cache — refusing to make it part of the pool. Nothing was changed."
        exit 1
    fi
    $SUDO pvcreate -q -y "$1" >/dev/null
}

# The dm-cache chunk size for a fast volume, in KiB: the smallest power of two from 64 KiB up
# that keeps it under LVM's cap of a million chunks (allocation/cache_pool_max_chunks). LVM's
# own default is 64 KiB whatever the size, which a 1 TB SSD — fifteen million chunks — makes it
# refuse outright. A chunk of a megabyte or more costs nothing here: what is cached is
# segments, megabytes each, read whole.
cache_chunk_kib() {
    local size chunk=64
    size=$(cache_lvm lvs --noheadings --units k --nosuffix -o lv_size "$1" | tr -d ' ')
    size=${size%%.*}
    while [ $(( ${size:-0} / chunk )) -ge 1000000 ]; do chunk=$(( chunk * 2 )); done
    echo "$chunk"
}

# The tiers over CACHE_BULK_DEVICE and CACHE_FAST_DEVICE, each step taken only when it is
# missing, so a deploy that died halfway through is finished by the next one rather than left
# as a pool that never mounts. LVM refuses to activate a cached volume without `cache_check`,
# which is a package of its own: missing, the pool would build and then not come back at boot.
cache_build_tiers() {
    local device
    ensure_commands lvcreate=lvm2 dmsetup=dmsetup cache_check=thin-provisioning-tools

    cache_make_pv "$CACHE_BULK_DEVICE"
    cache_make_pv "$CACHE_FAST_DEVICE"
    if ! cache_lvm vgs "$CACHE_VG" >/dev/null; then
        $SUDO vgcreate -q "$CACHE_VG" "$CACHE_BULK_DEVICE" "$CACHE_FAST_DEVICE" >/dev/null
    else
        for device in "$CACHE_BULK_DEVICE" "$CACHE_FAST_DEVICE"; do
            cache_in_vg "$device" || $SUDO vgextend -q "$CACHE_VG" "$device" >/dev/null
        done
    fi

    # Each volume on its own tier's device, named explicitly: left to choose, LVM would spread
    # the data volume over the fast device too.
    cache_lvm lvs "$CACHE_VG/$CACHE_LV" >/dev/null \
        || $SUDO lvcreate -q -y -W y -n "$CACHE_LV" -l 100%PVS "$CACHE_VG" "$CACHE_BULK_DEVICE" >/dev/null
    if [ "$(cache_lvm lvs --noheadings -o segtype "$CACHE_VG/$CACHE_LV" | tr -d ' ')" != "cache" ]; then
        cache_lvm lvs "$CACHE_VG/fast" >/dev/null \
            || $SUDO lvcreate -q -y -W y -n fast -l 100%PVS "$CACHE_VG" "$CACHE_FAST_DEVICE" >/dev/null
        $SUDO lvconvert -q -y --type cache --cachevol "$CACHE_VG/fast" --cachemode writethrough \
            --chunksize "$(cache_chunk_kib "$CACHE_VG/fast")k" "$CACHE_VG/$CACHE_LV" >/dev/null
    fi

    cache_activate_data
}

# The data volume, active and with its filesystem. Active before anything looks at it: an
# inactive volume has no device node, and a device that is not there reads as one with no
# filesystem. Stopping is the only answer that is never wrong here — formatting is never how a
# volume that would not activate gets fixed.
cache_activate_data() {
    $SUDO lvchange -q -ay "$CACHE_VG/$CACHE_LV" || true
    $SUDO udevadm settle 2>/dev/null || true
    if [ ! -b "$CACHE_LV_DEVICE" ]; then
        echo "ERROR: the cache volume $CACHE_VG/$CACHE_LV exists but will not activate (see above). Nothing was formatted."
        exit 1
    fi
    [ -n "$($SUDO blkid -p -o value -s TYPE "$CACHE_LV_DEVICE" 2>/dev/null)" ] || cache_format "$CACHE_LV_DEVICE"
}

# A tiered pool whose fast tier is gone — an SSD that died, an instance-store NVMe a cloud VM
# loses when it stops — keeps serving from the spinning tier. LVM will not activate a cached
# volume whose cache is missing, so the cache is detached first: safe because it is
# writethrough, and the spinning tier already has every block. Only when it is the fast tier
# that is missing: `vgreduce --removemissing` deletes whatever lived on the missing device, and
# a spinning disk that is merely slow to appear would take the pool with it. That case stops
# the deploy with nothing changed.
cache_repair_tiers() {
    local missing origin
    missing=$(cache_lvm vgs --noheadings -o vg_missing_pv_count "$CACHE_VG" | tr -d ' ')
    [ "${missing:-0}" -gt 0 ] 2>/dev/null || return 0

    # The data's own extents: `data_corig` while it is cached, `data` itself once it is not.
    origin=$(cache_lvm lvs -a --noheadings -o lv_name,devices "$CACHE_VG" \
        | awk -v corig="[${CACHE_LV}_corig]" -v lv="$CACHE_LV" '$1 == corig || $1 == lv { print $2 }' | tail -n1)
    if [ -z "$origin" ] || [[ "$origin" == *unknown* ]]; then
        echo "ERROR: the spinning tier of the cache pool is missing — the pool is left exactly as it is. Reconnect the disk and redeploy, or rebuild the pool."
        exit 1
    fi

    echo "WARNING: the fast tier of the cache pool is missing — detaching it and serving from the spinning tier"
    if [ "$(cache_lvm lvs --noheadings -o segtype "$CACHE_VG/$CACHE_LV" | tr -d ' ')" = "cache" ]; then
        $SUDO lvconvert -q -y --uncache --force "$CACHE_VG/$CACHE_LV" >/dev/null 2>&1 || true
    fi
    $SUDO vgreduce -q --removemissing --force "$CACHE_VG" >/dev/null 2>&1 || true
    CACHE_FAST_DEVICE=""
}

# An array's members as devices, which is not the inventory's list of disks: a member may be a
# partition carved next to the OS. Read by label only, so looking at loop devices too costs
# nothing, and it is what lets the assembly be exercised on them.
cache_array_members() {
    local member type
    lsblk -pnr -o KNAME,TYPE 2>/dev/null | while read -r member type; do
        case "$type" in disk|part|loop) ;; *) continue ;; esac
        cache_probe "$member"
        if [ "$FSTYPE" = "linux_raid_member" ] && [[ "$LABEL" == *"$1" ]]; then echo "$member"; fi
    done | sort -u
}

# Whether an array is assembled and running, as md itself says. Not whether its node exists — a
# stopped array can leave /dev/md/* behind — and not `mdadm --detail`, which also answers for an
# array assembled with a member missing: inactive, and read as an empty disk.
cache_array_active() {
    local device state
    device=$(readlink -f "$1" 2>/dev/null) || return 1
    [ -b "$device" ] || return 1
    state=$(cat "/sys/block/${device##*/}/md/array_state" 2>/dev/null)
    case "$state" in ""|clear|inactive) return 1 ;; esac
    return 0
}

cache_assemble_array() {
    local members
    members=$(cache_array_members "$2")
    if ! cache_array_active "$1" && [ -n "$members" ]; then
        # shellcheck disable=SC2086
        $SUDO mdadm --assemble "$1" $members || true
        $SUDO udevadm settle 2>/dev/null || true
    fi
}

# Members found are assembled, never rebuilt: udev assembles the arrays and LVM activates the
# volume group by itself at boot, and this covers a pool whose members were replugged since.
#
# A pool with nothing on it is a deploy that died partway — which every multi-disk deploy did
# while the label was one XFS refuses — and is finished here. A tiered pool is recognised by its
# volume group, or by its fast array when the deploy died before LVM; its tiers are told apart
# by which array they are, and by speed only for a tier on a bare disk.
cache_assemble_pool() {
    local pv real bulk_md fast_md
    cache_assemble_array "$CACHE_POOL_MD" "$CACHE_POOL_NAME"
    cache_assemble_array "$CACHE_FAST_MD" "$CACHE_FAST_NAME"
    bulk_md=$(readlink -f "$CACHE_POOL_MD" 2>/dev/null || true)
    fast_md=$(readlink -f "$CACHE_FAST_MD" 2>/dev/null || true)

    CACHE_BULK_DEVICE=""
    CACHE_FAST_DEVICE=""

    if cache_lvm vgs "$CACHE_VG" >/dev/null; then
        ensure_commands lvcreate=lvm2 dmsetup=dmsetup cache_check=thin-provisioning-tools
        for pv in $(cache_lvm pvs --noheadings -o pv_name -S vg_name="$CACHE_VG"); do
            real=$(readlink -f "$pv")
            if [ "$real" = "$fast_md" ]; then CACHE_FAST_DEVICE=$pv
            elif [ "$real" = "$bulk_md" ]; then CACHE_BULK_DEVICE=$pv
            elif cache_is_fast "$pv"; then CACHE_FAST_DEVICE=${CACHE_FAST_DEVICE:-$pv}
            else CACHE_BULK_DEVICE=${CACHE_BULK_DEVICE:-$pv}
            fi
        done
        $SUDO vgchange -q -ay "$CACHE_VG" >/dev/null 2>&1 || true
        cache_repair_tiers
        if [ -n "$CACHE_BULK_DEVICE" ] && [ -n "$CACHE_FAST_DEVICE" ]; then
            cache_build_tiers
        else
            cache_activate_data
        fi
    elif cache_array_active "$CACHE_FAST_MD" && cache_array_active "$CACHE_POOL_MD"; then
        echo "The tiers were never built — finishing what an earlier deploy started"
        CACHE_BULK_DEVICE=$CACHE_POOL_MD
        CACHE_FAST_DEVICE=$CACHE_FAST_MD
        cache_build_tiers
    elif cache_array_active "$CACHE_POOL_MD" && [ -z "$($SUDO blkid -p -o value -s TYPE "$CACHE_POOL_MD" 2>/dev/null)" ]; then
        echo "The array was never formatted — finishing what an earlier deploy started"
        cache_format "$CACHE_POOL_MD"
    fi
}

# Mounted by label, never by device name: names move between reboots, labels do not. `nofail`
# keeps a dead pool from holding the boot; the edge then comes up caching on an empty directory
# on the OS disk, which is the degraded mode, not an outage.
cache_mount_pool() {
    $SUDO mkdir -p "$CACHE_POOL_MOUNT"
    # The trailing space is part of the match: the label is a prefix of the array's name.
    grep -qF "LABEL=$CACHE_POOL_LABEL " /etc/fstab \
        || echo "LABEL=$CACHE_POOL_LABEL $CACHE_POOL_MOUNT xfs defaults,noatime,nodiratime,nofail 0 0" | $SUDO tee -a /etc/fstab >/dev/null
    # `|| true`: under `set -e` a failed mount would end the script on this line, before the
    # next one gets to say what failed.
    mountpoint -q "$CACHE_POOL_MOUNT" || $SUDO mount "$CACHE_POOL_MOUNT" || true
    # The label is resolved through blkid's cache, which can still name the array a rebuilt pool
    # replaced on this same boot. Asked again without it, the label names the device that
    # carries it now.
    if ! mountpoint -q "$CACHE_POOL_MOUNT"; then
        local device
        device=$($SUDO blkid -c /dev/null -o device -t LABEL="$CACHE_POOL_LABEL" 2>/dev/null | head -n1)
        [ -z "$device" ] || $SUDO mount -o noatime,nodiratime "$device" "$CACHE_POOL_MOUNT" || true
    fi
    mountpoint -q "$CACHE_POOL_MOUNT" || { echo "ERROR: cache pool could not be mounted at $CACHE_POOL_MOUNT"; exit 1; }

    $SUDO mkdir -p "$CACHE_DIRECTORY"
    # The marker lives on the pool itself, so it is absent exactly when the pool is not there.
    # Hidden, and never a cache entry: nginx's cache loader only indexes files whose names are
    # hex keys under its `levels` directories and ignores anything else it finds in the root.
    $SUDO touch "$CACHE_DIRECTORY/$CACHE_POOL_MARKER"
    CACHE_MOUNT=$CACHE_DIRECTORY
    df -h "$CACHE_POOL_MOUNT" | tail -1 | awk '{ print "Cache pool: " $2 " total, " $4 " free, mounted at " $6 }'
}

# The block layer under the pool, tuned for what an edge does to it: whole segments read
# start to end, by a cache that is also writing what it fetches.
#
#  - read_ahead_kb 4096: the kernel's 128 KB default split a multi-MB segment into dozens of
#    reads, and a pool on cloud block storage is capped in operations per second, not bytes —
#    at 200 IOPS, 128 KB reads are ~25 MB/s off a volume rated for 200. Readahead never reads
#    past the end of a file, so the small audio and subtitle segments cost the same as before.
#  - mq-deadline: reads are served ahead of writes (500 ms deadline against 5 s), so the cache
#    filling with a miss cannot starve the viewers reading hits. Not on NVMe, where `none` is
#    the better scheduler and there is no queue worth reordering.
#
# Applied now, and by a udev rule keyed on the pool's labels at every boot — numbered past 63,
# because the labels it matches on are only known once 60-persistent-storage.rules (a disk's
# filesystem) and 63-md-raid-arrays.rules (an array's) have run. Two cases wait for the next
# deploy after a reboot, because no label marks them: a pool member that is a partition (its
# queue is the OS disk's) and a tier on a single bare disk (an LVM volume, not an array).
CACHE_UDEV_RULE=/etc/udev/rules.d/99-nukevideo-cache.rules
CACHE_READ_AHEAD_KB=4096

cache_tune_queue() {
    local queue=/sys/block/$1/queue
    [ -d "$queue" ] || return 0
    echo "$CACHE_READ_AHEAD_KB" | $SUDO tee "$queue/read_ahead_kb" >/dev/null 2>&1 || true
    case "$1" in
        nvme*|md*|dm-*) ;;
        *) grep -qw mq-deadline "$queue/scheduler" 2>/dev/null \
               && { echo mq-deadline | $SUDO tee "$queue/scheduler" >/dev/null 2>&1 || true; } ;;
    esac
    local scheduler
    scheduler=$(sed -n 's/.*\[\(.*\)\].*/\1/p' "$queue/scheduler" 2>/dev/null)
    echo "  $1: readahead $(cat "$queue/read_ahead_kb" 2>/dev/null) KB, scheduler ${scheduler:-none (array: its members schedule)}"
}

# The whole device a block device's queue lives on: itself for a disk or an array, the disk it
# sits on for a partition.
cache_queue_of() {
    if [ -d "/sys/block/$1" ]; then echo "$1"; else lsblk -no PKNAME "/dev/$1" 2>/dev/null | head -n1; fi
}

cache_tune_pool() {
    local source queue
    source=$(findmnt -n -o SOURCE --target "$CACHE_POOL_MOUNT" 2>/dev/null) || return 0

    # Every layer between the filesystem and the disks — a cached volume, its tiers, the arrays,
    # the partitions — down to the disks themselves, each queue once.
    for queue in $(lsblk -s -nr -o KNAME "$source" 2>/dev/null | while read -r kname; do cache_queue_of "$kname"; done | sort -u); do
        cache_tune_queue "$queue"
    done

    # Not fatal: the queues are already tuned for this boot, and a pool must not fail its deploy
    # over a rule that only matters at the next one.
    $SUDO tee "$CACHE_UDEV_RULE" >/dev/null 2>&1 <<RULES || echo "WARNING: could not write $CACHE_UDEV_RULE"
# Written by the nukevideo deploy (resources/deploy/cache-disks.sh); see cache_tune_pool there.
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_LABEL}=="$CACHE_POOL_LABEL", ATTR{queue/read_ahead_kb}="$CACHE_READ_AHEAD_KB"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_LABEL}=="$CACHE_POOL_LABEL", KERNEL!="nvme*|md*|dm-*", ATTR{queue/scheduler}="mq-deadline"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_TYPE}=="linux_raid_member", ENV{ID_FS_LABEL}=="*$CACHE_POOL_NAME|*$CACHE_FAST_NAME", ATTR{queue/read_ahead_kb}="$CACHE_READ_AHEAD_KB"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_TYPE}=="linux_raid_member", ENV{ID_FS_LABEL}=="*$CACHE_POOL_NAME|*$CACHE_FAST_NAME", KERNEL!="nvme*", ATTR{queue/scheduler}="mq-deadline"
RULES
    $SUDO udevadm control --reload 2>/dev/null || true
}

# Leaves CACHE_MOUNT pointing at this node's directory on the pool, or empty when the host
# has no disk, and no room on its system disks, to give — the caller then falls back to a docker volume. Idempotent on the pool:
# a redeploy, which is how a node is updated, keeps the cache. Growing a RAID 0 means
# rebuilding it, which is a decision to take from the panel (wipe the label, redeploy), not a
# side effect of an update: a spare disk next to an existing pool is reported, not absorbed.
provision_cache_pool() {
    echo "=== Cache disks ==="
    ensure_commands mdadm=mdadm mkfs.xfs=xfsprogs
    # What reads a tier of this cache back, and tells it from somebody else's LVM disk.
    ensure_commands lvcreate=lvm2 || echo "Continuing without LVM: LVM disks are left untouched, and a mix of disk kinds makes one pool"
    # What reads and extends a system disk's partition table. Its absence only means such a
    # disk is not offered, so a host that cannot install it still deploys.
    ensure_commands sfdisk=fdisk || echo "Continuing without sfdisk: no system disk will be offered for sharing"

    INVENTORY=$(cache_disk_inventory)
    echo "$INVENTORY" | awk -F'\t' '{ printf "  %-14s %8.0f GB  %-9s %s %s%s\n", $1, $2/1e9, $4, $3, $5, ($6 > 0 ? sprintf(" (%.0f GB)", $6/1e9) : "") }'

    POOL_DISKS=$(echo "$INVENTORY" | awk -F'\t' '$4 == "nukevideo" { print $1 }')
    cache_select_disks

    if [ -n "$POOL_DISKS" ]; then
        echo "Existing cache pool found — keeping it"
        [ -n "$FREE_DISKS$SHARED_DISKS" ] && echo "WARNING: disks not part of the pool are left untouched:" $FREE_DISKS $SHARED_DISKS
        cache_assemble_pool
        cache_mount_pool
    elif [ -n "$FREE_DISKS$SHARED_DISKS" ]; then
        [ -n "$FREE_DISKS" ] && echo "Formatting: ${FREE_DISKS//$'\n'/ }"
        [ -n "$SHARED_DISKS" ] && echo "Adding a partition over the unpartitioned space of: ${SHARED_DISKS//$'\n'/ }"
        # Carved before anything is wiped: of the two it is the step that can still refuse,
        # and a refusal should find the spare disks as they were.
        cache_carve_partitions
        cache_wipe_disks
        POOL_DEVICES=$(printf '%s\n' $FREE_DISKS $CARVED_PARTS)
        cache_create_pool
        cache_mount_pool
    else
        echo "WARNING: no disk for the pool (none spare, or none selected) — the edge will cache into a docker volume on the OS disk, capped at $CACHE_FALLBACK_MAX_SIZE"
    fi
}
