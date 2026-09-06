#!/usr/bin/env bash
# Starts the demo web server. Runs on every container start (postStartCommand),
# including the first one, right after postCreate.sh has seeded the data.
#
# Why a script rather than one inline command: devcontainer lifecycle commands
# are handed to /bin/sh, which on these images is dash, and the obvious inline
# form (`nohup php -S ... & disown`) dies there on `disown: not found` - a
# bashism dash does not have. It fails invisibly, too: the port still appears
# in the Ports panel either way, because forwardPorts registers it whether or
# not anything is listening, so the only symptom is a blank page. Hence both
# the setsid below and the health check at the end - if this cannot serve a
# page, it says so here rather than leaving it to be diagnosed from the far
# side of a port forward.
set -euo pipefail
cd "$(dirname "$0")/.."

port=8080
log=/tmp/rust-server.log

# Any HTTP response counts: even the "not protected" 403 means PHP is answering.
# Only a refused connection is the failure this guards against.
serving() { curl -s -o /dev/null "http://127.0.0.1:$port/"; }

if serving; then
    echo "Růst is already serving on port $port."
    exit 0
fi

# setsid puts the server in its own session, so it outlives this script
# instead of being torn down along with the lifecycle command's process group.
setsid nohup php -S "0.0.0.0:$port" -t src > "$log" 2>&1 < /dev/null &

for _ in $(seq 1 50); do
    if serving; then
        echo "Růst is serving on port $port. Open the Ports panel (next to the"
        echo "terminal), find port $port, and click the globe icon to open it."
        exit 0
    fi
    sleep 0.2
done

echo "The server did not answer on port $port within 10 seconds." >&2
echo "Start it by hand with: php -S 0.0.0.0:$port -t src" >&2
echo "--- $log ---" >&2
cat "$log" >&2 || true
exit 1
