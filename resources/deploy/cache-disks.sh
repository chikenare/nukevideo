# The edge cache disks of a proxy node: finding them, formatting them and mounting them.
# Sourced by the panel's disk listing (inventory only) and by the proxy deploy (the lot).
# See App\Services\ProxyCacheService for the reasoning; the constants there mirror these.

CACHE_POOL_MOUNT=/var/lib/nukevideo/cache
# The filesystem label. XFS takes twelve characters and mkfs.xfs refuses a longer one outright,
# so it cannot be the array's name.
CACHE_POOL_LABEL=nukevideo
CACHE_POOL_MD=/dev/md/nukevideo-cache
CACHE_POOL_NAME=${CACHE_POOL_MD##*/}
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

# One RAID 0 across the chosen devices (plain XFS on a single one), labelled so the next deploy
# recognises it. 512k chunks: a segment is megabytes, so each read still spans every member;
# mkfs.xfs reads the geometry back from md and aligns its stripe unit to it. A carved partition
# is a member like any whole disk: md stripes unequal members by zones.
cache_create_pool() {
    local count fs_device
    count=$(echo "$POOL_DEVICES" | wc -l)

    if [ "$count" -gt 1 ]; then
        $SUDO mdadm --create "$CACHE_POOL_MD" --level=0 --chunk=512K --metadata=1.2 --name="$CACHE_POOL_NAME" \
            --raid-devices="$count" --run $POOL_DEVICES
        $SUDO mkdir -p /etc/mdadm
        $SUDO touch /etc/mdadm/mdadm.conf
        grep -qF "$CACHE_POOL_NAME" /etc/mdadm/mdadm.conf \
            || $SUDO mdadm --detail --scan | grep -F "$CACHE_POOL_NAME" | $SUDO tee -a /etc/mdadm/mdadm.conf >/dev/null
        fs_device=$CACHE_POOL_MD
    else
        fs_device=$POOL_DEVICES
    fi
    # `-K`: no discard of the whole device first. On a thin-provisioned virtual disk the TRIM
    # runs at the backend's pace — 0.2 GB/s on an 11 TB volume, fourteen hours — and the SSH
    # session of the deploy gives up long before; the blocks are about to be filled with cache
    # anyway, so handing them back to the host first buys nothing.
    $SUDO mkfs.xfs -q -f -K -L "$CACHE_POOL_LABEL" "$fs_device"
}

# The array's members as devices, which is not the inventory's list of disks: a member may be
# a partition carved next to the OS. Empty for a single-device pool, which has no array.
cache_pool_members() {
    local member type
    lsblk -pnr -o KNAME,TYPE 2>/dev/null | while read -r member type; do
        [ "$type" = "disk" ] || [ "$type" = "part" ] || continue
        cache_probe "$member"
        if [ "$FSTYPE" = "linux_raid_member" ] && [[ "$LABEL" == *"$CACHE_POOL_NAME" ]]; then echo "$member"; fi
    done | sort -u
}

# Members found are assembled, never rebuilt. udev assembles the array by itself at boot; this
# only covers a pool whose members were replugged since.
#
# An array of ours with nothing on it is a deploy that died between mdadm and mkfs — which
# every multi-disk deploy did while the label was one XFS refuses. It is finished here rather
# than left as a pool that can never be mounted. No `-f`: mkfs.xfs itself refuses a device it
# finds a filesystem on, so this cannot be what erases a cache blkid failed to see.
cache_assemble_pool() {
    local members
    members=$(cache_pool_members)
    if [ ! -e "$CACHE_POOL_MD" ] && [ -n "$members" ]; then
        $SUDO mdadm --assemble "$CACHE_POOL_MD" $members || true
        $SUDO udevadm settle 2>/dev/null || true
    fi
    if [ -e "$CACHE_POOL_MD" ] && [ -z "$($SUDO blkid -p -o value -s TYPE "$CACHE_POOL_MD" 2>/dev/null)" ]; then
        echo "The array was never formatted — finishing what an earlier deploy started"
        $SUDO mkfs.xfs -q -K -L "$CACHE_POOL_LABEL" "$CACHE_POOL_MD"
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
# filesystem) and 63-md-raid-arrays.rules (an array's) have run. A pool member that
# is a partition shares its queue with the OS disk; the deploy tunes that disk now, but the
# rule only matches whole devices, so after a reboot it waits for the next deploy.
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
    local source kname member
    source=$(findmnt -n -o SOURCE --target "$CACHE_POOL_MOUNT" 2>/dev/null) || return 0
    kname=$(lsblk -no KNAME "$source" 2>/dev/null | head -n1)
    [ -n "$kname" ] || return 0

    cache_tune_queue "$(cache_queue_of "$kname")"
    for member in /sys/block/"$kname"/slaves/*; do
        [ -e "$member" ] && cache_tune_queue "$(cache_queue_of "$(basename "$member")")"
    done

    # Not fatal: the queues are already tuned for this boot, and a pool must not fail its deploy
    # over a rule that only matters at the next one.
    $SUDO tee "$CACHE_UDEV_RULE" >/dev/null 2>&1 <<RULES || echo "WARNING: could not write $CACHE_UDEV_RULE"
# Written by the nukevideo deploy (resources/deploy/cache-disks.sh); see cache_tune_pool there.
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_LABEL}=="$CACHE_POOL_LABEL", ATTR{queue/read_ahead_kb}="$CACHE_READ_AHEAD_KB"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_LABEL}=="$CACHE_POOL_LABEL", KERNEL!="nvme*|md*|dm-*", ATTR{queue/scheduler}="mq-deadline"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_TYPE}=="linux_raid_member", ENV{ID_FS_LABEL}=="*$CACHE_POOL_NAME", ATTR{queue/read_ahead_kb}="$CACHE_READ_AHEAD_KB"
ACTION=="add|change", SUBSYSTEM=="block", ENV{DEVTYPE}=="disk", ENV{ID_FS_TYPE}=="linux_raid_member", ENV{ID_FS_LABEL}=="*$CACHE_POOL_NAME", KERNEL!="nvme*", ATTR{queue/scheduler}="mq-deadline"
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
