#!/usr/bin/env bash
#
# Railway entrypoint: runs the OCI polling worker in the background and a
# static status page in the foreground, on the same container/port.
#
# If either process dies, the container exits so Railway can restart it
# cleanly - we never want the status page silently serving stale state
# while the worker is dead, or vice versa.
set -u

php worker.php &
WORKER_PID=$!

PORT="${PORT:-8080}"
php -S 0.0.0.0:"$PORT" -t public &
WEB_PID=$!

term_handler() {
  kill -TERM "$WORKER_PID" "$WEB_PID" 2>/dev/null
}
trap term_handler TERM INT

wait -n "$WORKER_PID" "$WEB_PID"
EXIT_CODE=$?

kill -TERM "$WORKER_PID" "$WEB_PID" 2>/dev/null
exit "$EXIT_CODE"
