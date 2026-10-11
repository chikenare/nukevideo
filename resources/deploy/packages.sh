# The tools the node scripts call that a host is not sure to have, installed in one place.
# Sourced right after sudo.sh by the deploy and by the panel's disk listing.
#
# Every script used to install its own, each in its own way, and the ways disagreed: one ran
# `apt-get update` first and the next did not, so on a fresh host whose package lists had never
# been fetched the second install failed — silently, behind an `|| true` — and the first deploy
# of a proxy went on without sfdisk. A tool is named here once, next to the package that ships
# it, and a host that still lacks it afterwards is told which.

# Seconds to wait for another apt to let go of its lock. A server's first hours are when
# unattended-upgrades holds it, which is exactly when a first deploy runs.
APT_LOCK_TIMEOUT=${APT_LOCK_TIMEOUT:-120}

# Asked through sudo, whose PATH has the sbin directories: a non-root deploy user's does not,
# and a plain `command -v` then called a tool that lives there missing — and reinstalled it —
# on every deploy.
have_command() {
    $SUDO sh -c 'command -v "$1" >/dev/null 2>&1' _ "$1"
}

# ensure_commands command=package ["command=package another-package" ...]
#
# Installs the packages of whichever commands are missing and returns non-zero, having said
# which, when any still is. Under the deploy's `set -e` a bare call therefore ends the run; a
# caller that can do without the tool follows it with `||`.
ensure_commands() {
    local spec missing="" packages="" still=""
    for spec in "$@"; do
        have_command "${spec%%=*}" && continue
        missing="$missing ${spec%%=*}"
        case " $packages " in *" ${spec#*=} "*) ;; *) packages="$packages ${spec#*=}" ;; esac
    done
    [ -n "$missing" ] || return 0

    echo "Installing what this host lacks:$missing"
    if have_command apt-get; then
        # Updated first, always: the lists of a fresh image are empty or months old, and an
        # install against them fails on a package that exists. Not fatal by itself — a single
        # dead third-party repository fails the update while everything needed is still there.
        $SUDO env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_TIMEOUT" update -qq || true
        # No recommends: the RAID tools' pull in a mail server, which then asks how to be
        # configured.
        # shellcheck disable=SC2086
        $SUDO env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$APT_LOCK_TIMEOUT" \
            install -y -qq --no-install-recommends $packages || true
    fi

    for spec in "$@"; do
        have_command "${spec%%=*}" || still="$still ${spec%%=*}"
    done
    [ -z "$still" ] && return 0

    if have_command apt-get; then
        echo "ERROR: could not install:$still. Run 'apt-get install$packages' on this host to see why." >&2
    else
        echo "ERROR: this host lacks:$still, and has no apt-get to install them with. Install them with its own package manager (Debian names:$packages)." >&2
    fi
    return 1
}
