# Root, or a user whose sudo asks no questions — settled once, up front, by everything the API
# runs on a node over SSH: the deploy (first section after the header) and the panel's disk
# inventory, which needs root to read raw devices and must fail with the same message rather
# than pass a partitioned disk off as an empty one.
#
# The API runs this over SSH with no terminal (BatchMode), so a sudo that wants a password
# cannot get one: it fails, and every `|| true` in the deploy used to turn that into one that
# "succeeded" with Docker never enabled and the user never in the docker group. Checked once,
# with the reason spelled out; `-n` stays on every later call so a rule revoked mid-run fails
# instead of hanging the session.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    SUDO="sudo -n"
    if ! sudo -n true 2>/dev/null; then
        echo "ERROR: $(id -un) cannot sudo without a password, and this session has no terminal to type one in." >&2
        echo "Deploy as root, or grant passwordless sudo: echo '$(id -un) ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/nukevideo" >&2
        exit 1
    fi
fi
