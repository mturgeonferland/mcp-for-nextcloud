#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs ONE cell of the integration matrix on a Mac, in disposable containers on Colima, through the same harness.sh the
# GitHub workflow runs. The container engine is started only for the run and is always stopped again, on success, on
# error and on INT/TERM: the test containers, their network and volumes are removed first, then `colima stop` runs, but
# only when this script is the one that started it (an engine that was already running is left running).
#
# Several runs (other agents, other terminals) share the one engine without touching each other: every run names its
# containers and network with its own id and removes only those, and runs take the engine one after the other through
# a lock (~/.colima/mcp-it.lock, a directory holding the owner's pid; a dead owner's lock is taken over). A run that
# started the engine stops it only when no other run is waiting; otherwise the engine stays up and the next run takes
# over the duty to stop it (~/.colima/mcp-it.engine).
#
# Usage: run-local.sh <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade|baseline> [--prune-images]
#        run-local.sh --shutdown   (the only safe way to stop the engine by hand; see above)
#   baseline        diagnostics: the released baseline stays installed, never upgraded (use with MCP_IT_PHPUNIT_ARGS)
#   --prune-images  also removes the images this run pulled (default: kept, they are only files on disk)
#
# Logs and artifacts (run.log, resources.log, mcp-it/artifacts/: junit.xml, nextcloud.log, observations.txt,
# provision.log) go to mcp/tests/Integration/.local-runs/<cell>-<timestamp>/ (git-ignored). Image digests, the
# baseline tag and the cell come from .github/workflows/integration.yml, so there is one list of pins.
set -eu

# Runs take the engine one after the other: the lock is a directory holding the pid of the run that owns it.
LOCK_DIR="${MCP_IT_LOCK_DIR:-$HOME/.colima/mcp-it.lock}"
QUEUE_DIR="${LOCK_DIR%.lock}.queue"
# Present while a run of this script owns the duty to stop the engine (it started it); handed to the next run in the queue.
ENGINE_FLAG="${LOCK_DIR%.lock}.engine"
HAVE_LOCK=0

log() {
    echo "[run-local $(date +%H:%M:%S)] $*"
}

acquire_lock() {
    mkdir -p "$(dirname "$LOCK_DIR")" "$QUEUE_DIR"
    echo "$$" > "$QUEUE_DIR/$$"
    waited=0
    until mkdir "$LOCK_DIR" 2>/dev/null; do
        owner=$(cat "$LOCK_DIR/pid" 2>/dev/null || true)
        if [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; then
            log "the run $owner that held the engine is gone; taking its lock"
            rm -rf "$LOCK_DIR"
            continue
        fi
        [ "$waited" -ge 3600 ] && { rm -f "$QUEUE_DIR/$$"; echo "Timed out waiting for the engine lock held by run ${owner:-?}" >&2; exit 3; }
        [ $((waited % 60)) -eq 0 ] && log "waiting for the run ${owner:-?} to finish with the engine"
        sleep 5
        waited=$((waited + 5))
    done
    echo "$$" > "$LOCK_DIR/pid"
    rm -f "$QUEUE_DIR/$$"
    HAVE_LOCK=1
}

# Whether another live run is waiting for the engine (stale entries of dead runs are dropped).
others_waiting() {
    found=1
    for entry in "$QUEUE_DIR"/*; do
        [ -e "$entry" ] || continue
        pid=$(cat "$entry" 2>/dev/null || true)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            found=0
        else
            rm -f "$entry"
        fi
    done
    return $found
}


# run-local.sh --shutdown: the only safe way to stop the engine by hand. It takes its turn in the queue like a run, so it
# never cuts a run in progress, removes the containers and networks a dead run left behind, and stops Colima only when
# nobody is waiting (otherwise the next run takes over stopping it).
if [ "${1:-}" = "--shutdown" ]; then
    command -v colima >/dev/null 2>&1 || { echo "colima is not installed" >&2; exit 2; }
    trap '[ "$HAVE_LOCK" = 1 ] && rm -rf "$LOCK_DIR"' EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    acquire_lock
    if colima status >/dev/null 2>&1; then
        # Holding the lock means no run is using the engine, so what is named mcp-it-* is left over.
        ids=$(docker ps -aq --filter 'name=mcp-it-' 2>/dev/null || true)
        [ -n "$ids" ] && docker rm -f -v $ids >/dev/null 2>&1
        for net in $(docker network ls --format '{{.Name}}' --filter 'name=mcp-it' 2>/dev/null || true); do
            docker network rm "$net" >/dev/null 2>&1 || true
        done
        if others_waiting; then
            echo "$$" > "$ENGINE_FLAG"
            log "another run is waiting for the engine: Colima stays up for it, and it takes over stopping it"
        else
            log "stopping Colima (nobody is waiting)"
            colima stop
            rm -f "$ENGINE_FLAG"
        fi
    else
        log "Colima is not running"
        rm -f "$ENGINE_FLAG"
    fi
    exit 0
fi

usage() {
    echo "Usage: $0 <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade|baseline> [--prune-images] | $0 --shutdown" >&2
    exit 2
}

[ $# -ge 3 ] || usage
CELL_NC=$1
CELL_DB=$2
CELL_MODE=$3
PRUNE_IMAGES=0
[ "${4:-}" = "--prune-images" ] && PRUNE_IMAGES=1
case "$CELL_MODE" in fresh|upgrade|baseline) ;; *) usage ;; esac

HERE=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "$HERE/../../../.." && pwd)
WORKFLOW="$REPO_ROOT/.github/workflows/integration.yml"
STAMP=$(date +%Y%m%d-%H%M%S)
LOCAL_DIR="$REPO_ROOT/mcp/tests/Integration/.local-runs/nc$CELL_NC-$CELL_DB-$CELL_MODE-$STAMP"
mkdir -p "$LOCAL_DIR"

# cell_field <field>: the value of the cell's field in the workflow matrix ('' for an empty value).
cell_field() {
    awk -v nc="$CELL_NC" -v db="$CELL_DB" -v field="$1" '
        $1 == "-" && $2 == "nc:" { cur = $3; gsub(/[^0-9]/, "", cur); curdb = "" }
        $1 == "db:" { curdb = $2 }
        cur == nc && curdb == db && $1 == field ":" { value = $2; gsub(/\047/, "", value); print value; exit }
    ' "$WORKFLOW"
}

NC_IMAGE=$(cell_field nc_image)
DB_IMAGE=$(cell_field db_image)
BASELINE=$(sed -n 's/^ *MCP_IT_BASELINE: *//p' "$WORKFLOW" | head -n 1)
if [ -z "$NC_IMAGE" ] || { [ "$CELL_DB" != sqlite ] && [ -z "$DB_IMAGE" ]; }; then
    echo "No cell NC $CELL_NC / $CELL_DB in $WORKFLOW" >&2
    exit 2
fi

# One name per run: two runs on the same engine (another agent, another terminal) never see each other's containers.
RUN_ID="$STAMP-$$"
NET_NAME="mcp-it-$RUN_ID"
NC_NAME="mcp-it-nextcloud-$RUN_ID"
DB_NAME="mcp-it-db-$RUN_ID"
export MCP_IT_NET="$NET_NAME" MCP_IT_NC_NAME="$NC_NAME" MCP_IT_DB_NAME="$DB_NAME"
export MCP_IT_LOCAL=1
export RUNNER_TEMP="$LOCAL_DIR"
export MCP_IT_NC="$CELL_NC" MCP_IT_NC_IMAGE="$NC_IMAGE" MCP_IT_DB="$CELL_DB" MCP_IT_DB_IMAGE="$DB_IMAGE"
export MCP_IT_MODE="$CELL_MODE" MCP_IT_BASELINE="$BASELINE"
HARNESS="$HERE/harness.sh"
WORK="$LOCAL_DIR/mcp-it"
STARTED_COLIMA=0
SAMPLER_PID=""
START_TIME=$(date +%s)

# Samples the memory the engine and the containers use, every 10 s, for the summary at the end.
sample_resources() {
    while :; do
        {
            echo "t=$(date +%s)"
            ps -axo rss=,command= | awk 'tolower($0) ~ /limactl|colima|virtualization|qemu/ { sum += $1 } END { printf "vm_rss_kb=%d\n", sum }'
            docker stats --no-stream --format 'container={{.Name}} mem={{.MemUsage}} cpu={{.CPUPerc}}' 2>/dev/null || true
        } >> "$LOCAL_DIR/resources.log"
        sleep 10
    done
}

cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    [ -n "$SAMPLER_PID" ] && kill "$SAMPLER_PID" 2>/dev/null
    if docker info >/dev/null 2>&1; then
        log "removing the test containers, network and volumes"
        # Inspect only this run's anonymous volumes, before rm -v loses their names.
        volumes=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' \
            "$NC_NAME" "$DB_NAME" 2>/dev/null || true)
        docker rm -f -v "$NC_NAME" "$DB_NAME" >/dev/null 2>&1
        docker network rm "$NET_NAME" >/dev/null 2>&1
        # Prove removal while the daemon is still up; a stopped daemon cannot prove an empty inventory.
        remaining=$(docker ps -aq --filter "name=$NC_NAME" --filter "name=$DB_NAME") || remaining=unavailable
        networks=$(docker network ls --filter "name=^$NET_NAME$" --format '{{.Name}}') || networks=unavailable
        leftover_volumes=""
        for volume in $volumes; do
            if docker volume inspect "$volume" >/dev/null 2>&1; then leftover_volumes="$leftover_volumes $volume"; fi
        done
        printf 'containers=%s\nnetwork=%s\nvolumes=%s\n' "${remaining:-none}" "${networks:-none}" "${leftover_volumes:-none}" \
            > "$LOCAL_DIR/cleanup.txt"
        if [ -n "$remaining$networks$leftover_volumes" ]; then
            log "cleanup left resources or could not verify removal (see cleanup.txt)"
            status=1
        fi
        if [ "$PRUNE_IMAGES" = 1 ]; then
            for image in "$NC_IMAGE" "$DB_IMAGE"; do
                [ -n "$image" ] && docker rmi "$image" >/dev/null 2>&1
            done
        fi
    fi
    if [ -d "$WORK/baseline" ]; then
        git -C "$REPO_ROOT" worktree remove --force "$WORK/baseline" >/dev/null 2>&1
        git -C "$REPO_ROOT" worktree prune
    fi
    # The downloads and packages of this run are rebuilt every time; only the logs and artifacts stay.
    rm -rf "$WORK/tools" "$WORK/branch-pkg" "$WORK/baseline-pkg" "$WORK/pglog" "$WORK"/*.tar.gz
    if [ "$STARTED_COLIMA" = 1 ]; then
        if others_waiting; then
            log "another run is waiting for the engine: Colima stays up for it, and it takes over stopping it"
        else
            log "stopping Colima (a run of this script started it and nobody is waiting)"
            colima stop
            rm -f "$ENGINE_FLAG"
        fi
    else
        log "Colima was already running before this run; left as it was"
    fi
    summary
    [ "$HAVE_LOCK" = 1 ] && rm -rf "$LOCK_DIR"
    exit "$status"
}

summary() {
    elapsed=$(($(date +%s) - START_TIME))
    peak_vm=$(awk -F= '/^vm_rss_kb=/ { if ($2 > max) max = $2 } END { printf "%d", max / 1024 }' "$LOCAL_DIR/resources.log" 2>/dev/null)
    log "elapsed ${elapsed}s; peak resident memory of the engine processes on the host ${peak_vm:-0} MB; logs in $LOCAL_DIR"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for tool in colima docker git php composer curl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "$tool is required (brew install colima docker)" >&2; exit 2; }
done

acquire_lock
if colima status >/dev/null 2>&1; then
    if [ -f "$ENGINE_FLAG" ]; then
        log "Colima was started by a run of this script that handed it over; this run stops it when nobody waits"
        STARTED_COLIMA=1
    else
        log "Colima is already running (not started by this script); this run will not stop it"
    fi
else
    log "starting Colima (4 CPUs, 8 GiB)"
    STARTED_COLIMA=1
    echo "$$" > "$ENGINE_FLAG"
    colima start --vm-type vz --mount-type virtiofs --cpu 4 --memory 8 --disk 40
fi
docker info >/dev/null

log "cell: NC $CELL_NC, $CELL_DB, $CELL_MODE (baseline $BASELINE)"

# The pins are digests of the image index when they are multi-architecture; record what this machine actually got.
for image in "$NC_IMAGE" "$DB_IMAGE"; do
    [ -n "$image" ] || continue
    docker pull --quiet "$image" >/dev/null
    log "image $image -> $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")"
done

sample_resources &
SAMPLER_PID=$!

failed=0
for step in packages tools start-db start-nextcloud install-app fixtures provision report; do
    # The provisioning of groupfolders, external storage, Talk, Notes and Calendar exists only on the lines that prove
    # the exclusion (issue #4); the harness of this line has no such step.
    if [ "$step" = provision ] && ! grep -q '^    provision)' "$HARNESS"; then
        continue
    fi
    log "step: $step"
    if ! sh "$HARNESS" "$step"; then
        log "step $step FAILED"
        failed=1
        break
    fi
done

test_status=0
if [ "$failed" = 0 ]; then
    log "step: test"
    sh "$HARNESS" test || test_status=$?
fi
log "step: collect"
sh "$HARNESS" collect || true

if [ "$failed" != 0 ]; then
    exit 1
fi
exit "$test_status"
