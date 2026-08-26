# The edge cache disks of a proxy node: finding them, formatting them and mounting them.
# Sourced by the panel's disk listing (inventory only) and by the proxy deploy (the lot).
# See App\Services\ProxyCacheService for the reasoning; the constants there mirror these.

CACHE_POOL_MOUNT=/var/lib/nukevideo/cache
CACHE_POOL_LABEL=nukevideo-cache
CACHE_POOL_MD=/dev/md/nukevideo-cache
# Written at the root of the node's cache directory on the pool; the edge's entrypoint looks for
# it to tell a mounted pool from the bare mount point directory on the OS disk (`nofail` lets
# the host boot without the pool, and the directory is then just a directory).
CACHE_POOL_MARKER=.nukevideo-pool

# One line per whole disk on the host: `device<TAB>size<TAB>model<TAB>state<TAB>detail`. The
# state is the whole decision:
#   system    — something under it is mounted (other than our pool) or is swap. Untouchable.
#   nukevideo — carries this cache already: a member of our array or our labelled XFS.
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

        # The subtree: partitions, and the md/LVM volumes assembled from them, with whatever is
        # mounted on any of those. That is what makes the OS disks visible as such even when /
        # sits on an md mirror or an LVM volume two layers down.
        while IFS= read -r part; do
            [ -n "$part" ] || continue
            eval "$part"
            MOUNTPOINT=$(printf '%b' "$MOUNTPOINT")
            if [ -n "$MOUNTPOINT" ] && [ "$MOUNTPOINT" != "$CACHE_POOL_MOUNT" ] && [ "$MOUNTPOINT" != "[SWAP]" ]; then
                state="system"; detail="mounted at $MOUNTPOINT"; break
            fi
            if [ "$FSTYPE" = "swap" ] || [ "$MOUNTPOINT" = "[SWAP]" ]; then
                state="system"; detail="swap"; break
            fi
            # mdadm stores the array name as `{homehost}:{name}`, hence the suffix match.
            if [ "$FSTYPE" = "xfs" ] && [ "$LABEL" = "$CACHE_POOL_LABEL" ]; then
                state="nukevideo"; detail="nukevideo cache (xfs)"
            elif [ "$FSTYPE" = "linux_raid_member" ] && [[ "$LABEL" == *"$CACHE_POOL_LABEL" ]]; then
                state="nukevideo"; detail="nukevideo cache (raid member)"
            elif [ "$state" = "empty" ] && [ -n "$FSTYPE" ]; then
                state="foreign"; detail="$FSTYPE${LABEL:+ \"$LABEL\"}"
            fi
        done <<< "$(lsblk -n -P -o FSTYPE,LABEL,MOUNTPOINT "$dev" 2>/dev/null)"

        # lsblk reports filesystems, not bare partition tables or a signature it does not know:
        # a partitioned disk with no filesystems still has data on it. Needs root to read the
        # raw device — without it such a disk passes for an empty one.
        if [ "$state" = "empty" ]; then
            sig=$($SUDO blkid -p -o value -s PTTYPE -s TYPE "$dev" 2>/dev/null | head -n1)
            [ -n "$sig" ] && { state="foreign"; detail="$sig partition table"; }
        fi

        printf '%s\t%s\t%s\t%s\t%s\n' "$dev" "$SIZE" "$(printf '%b' "$MODEL")" "$state" "$detail"
    done
}

# The spare disks the deploy may format: every `empty`/`foreign` one, narrowed to CHOSEN_DISKS
# when the panel sent a list. A disk the operator did not tick is reported and left exactly as
# it is. The selection never reaches beyond the spare ones: a system disk is out before it
# applies.
cache_select_disks() {
    FREE_DISKS=$(echo "$INVENTORY" | awk -F'\t' '$4 == "empty" || $4 == "foreign" { print $1 }')
    [ -n "${CHOSEN_DISKS+x}" ] || return 0

    local skipped
    skipped=$(for d in $FREE_DISKS; do case " $CHOSEN_DISKS " in *" $d "*) ;; *) echo "$d" ;; esac; done)
    FREE_DISKS=$(for d in $FREE_DISKS; do case " $CHOSEN_DISKS " in *" $d "*) echo "$d" ;; esac; done)
    [ -n "$skipped" ] && echo "Left untouched, not selected: ${skipped//$'\n'/ }"
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

# One RAID 0 across the chosen disks (plain XFS on a single one), labelled so the next deploy
# recognises it. 512k chunks: a segment is megabytes, so each read still spans every member;
# mkfs.xfs reads the geometry back from md and aligns its stripe unit to it.
cache_create_pool() {
    local count fs_device
    count=$(echo "$FREE_DISKS" | wc -l)

    if [ "$count" -gt 1 ]; then
        $SUDO mdadm --create "$CACHE_POOL_MD" --level=0 --chunk=512K --metadata=1.2 --name="$CACHE_POOL_LABEL" \
            --raid-devices="$count" --run $FREE_DISKS
        $SUDO mkdir -p /etc/mdadm
        $SUDO touch /etc/mdadm/mdadm.conf
        grep -qF "$CACHE_POOL_LABEL" /etc/mdadm/mdadm.conf \
            || $SUDO mdadm --detail --scan | grep -F "$CACHE_POOL_LABEL" | $SUDO tee -a /etc/mdadm/mdadm.conf >/dev/null
        fs_device=$CACHE_POOL_MD
    else
        fs_device=$FREE_DISKS
    fi
    $SUDO mkfs.xfs -q -f -L "$CACHE_POOL_LABEL" "$fs_device"
}

# Members found are assembled, never rebuilt. udev assembles the array by itself at boot; this
# only covers a pool whose members were replugged since. A single-disk pool has no array.
cache_assemble_pool() {
    if [ ! -e "$CACHE_POOL_MD" ] && $SUDO mdadm --examine $POOL_DISKS &>/dev/null; then
        $SUDO mdadm --assemble "$CACHE_POOL_MD" $POOL_DISKS || true
    fi
}

# Mounted by label, never by device name: names move between reboots, labels do not. `nofail`
# keeps a dead pool from holding the boot; the edge then comes up caching on an empty directory
# on the OS disk, which is the degraded mode, not an outage.
cache_mount_pool() {
    $SUDO mkdir -p "$CACHE_POOL_MOUNT"
    grep -qF "LABEL=$CACHE_POOL_LABEL" /etc/fstab \
        || echo "LABEL=$CACHE_POOL_LABEL $CACHE_POOL_MOUNT xfs defaults,noatime,nodiratime,nofail 0 0" | $SUDO tee -a /etc/fstab >/dev/null
    mountpoint -q "$CACHE_POOL_MOUNT" || $SUDO mount "$CACHE_POOL_MOUNT"
    mountpoint -q "$CACHE_POOL_MOUNT" || { echo "ERROR: cache pool could not be mounted at $CACHE_POOL_MOUNT"; exit 1; }

    $SUDO mkdir -p "$CACHE_DIRECTORY"
    # The marker lives on the pool itself, so it is absent exactly when the pool is not there.
    # Hidden, and never a cache entry: nginx's cache loader only indexes files whose names are
    # hex keys under its `levels` directories and ignores anything else it finds in the root.
    $SUDO touch "$CACHE_DIRECTORY/$CACHE_POOL_MARKER"
    CACHE_MOUNT=$CACHE_DIRECTORY
    df -h "$CACHE_POOL_MOUNT" | tail -1 | awk '{ print "Cache pool: " $2 " total, " $4 " free, mounted at " $6 }'
}

# Leaves CACHE_MOUNT pointing at this node's directory on the pool, or empty when the host
# has no disk to give — the caller then falls back to a docker volume. Idempotent on the pool:
# a redeploy, which is how a node is updated, keeps the cache. Growing a RAID 0 means
# rebuilding it, which is a decision to take from the panel (wipe the label, redeploy), not a
# side effect of an update: a spare disk next to an existing pool is reported, not absorbed.
provision_cache_pool() {
    echo "=== Cache disks ==="
    command -v mdadm &>/dev/null || { $SUDO apt-get update -qq && $SUDO apt-get install -y -qq mdadm xfsprogs; }
    command -v mkfs.xfs &>/dev/null || $SUDO apt-get install -y -qq xfsprogs

    INVENTORY=$(cache_disk_inventory)
    echo "$INVENTORY" | awk -F'\t' '{ printf "  %-14s %8.0f GB  %-8s %s %s\n", $1, $2/1e9, $4, $3, $5 }'

    POOL_DISKS=$(echo "$INVENTORY" | awk -F'\t' '$4 == "nukevideo" { print $1 }')
    cache_select_disks

    if [ -n "$POOL_DISKS" ]; then
        echo "Existing cache pool found — keeping it"
        [ -n "$FREE_DISKS" ] && echo "WARNING: disks not part of the pool are left untouched: ${FREE_DISKS//$'\n'/ }"
        cache_assemble_pool
        cache_mount_pool
    elif [ -n "$FREE_DISKS" ]; then
        echo "Formatting: ${FREE_DISKS//$'\n'/ }"
        cache_wipe_disks
        cache_create_pool
        cache_mount_pool
    else
        echo "WARNING: no disk for the pool (none spare, or none selected) — the edge will cache into a docker volume on the OS disk, capped at $CACHE_FALLBACK_MAX_SIZE"
    fi
}
