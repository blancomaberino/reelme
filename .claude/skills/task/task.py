#!/usr/bin/env python3
"""Read and update the Reelmap task queue.

`tasks/tasks.json` in the plan repo is the single source of truth for task state;
each task has a companion `tasks/T-###-*.md` with the full brief. This wraps the
handful of operations the T-### lifecycle needs so status never drifts from
reality and notes stay dated.

    task.py next                 # ready-to-start tasks, highest priority first
    task.py show T-039           # the task record + its companion brief
    task.py start T-039          # -> in_progress, prints the branch name to use
    task.py done T-039           # -> done
    task.py note T-039 "text"    # append a dated note (deviations, partial work)
    task.py status               # counts by phase
"""

from __future__ import annotations

import json
import os
import sys
from datetime import date
from pathlib import Path

# The plan lives in a sibling repo, not this one. Override with REELMAP_PLAN_DIR
# if yours is checked out somewhere other than ~/Sites/plans/reelmap.
PLAN = Path(os.environ.get("REELMAP_PLAN_DIR") or Path.home() / "Sites/plans/reelmap").expanduser()
TASKS_DIR = PLAN / "tasks"
TASKS_JSON = TASKS_DIR / "tasks.json"


def load() -> dict:
    if not TASKS_JSON.is_file():
        sys.exit(
            f"Task queue not found at {TASKS_JSON}.\n"
            "Set REELMAP_PLAN_DIR to your checkout of the plan repo."
        )
    return json.loads(TASKS_JSON.read_text())


def save(doc: dict) -> None:
    """Write atomically, preserving the file's 2-space indent + trailing newline."""
    tmp = TASKS_JSON.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(doc, indent=2, ensure_ascii=False) + "\n")
    tmp.replace(TASKS_JSON)


def find(doc: dict, task_id: str) -> dict:
    tid = task_id.upper()
    for t in doc["tasks"]:
        if t["id"].upper() == tid:
            return t
    sys.exit(f"No such task: {task_id}")


def brief_path(task_id: str) -> Path | None:
    hits = sorted(TASKS_DIR.glob(f"{task_id.upper()}-*.md"))
    return hits[0] if hits else None


def phase_rank(phase: str) -> tuple[int, int]:
    """ARCH is the current priority phase (see $schema_note); then M0..M5 in order."""
    if phase == "ARCH":
        return (0, 0)
    if phase.startswith("M") and phase[1:].isdigit():
        return (1, int(phase[1:]))
    return (2, 0)


def slug(task_id: str) -> str:
    p = brief_path(task_id)
    return p.stem if p else task_id.upper()


def cmd_next(doc: dict) -> None:
    done = {t["id"] for t in doc["tasks"] if t["status"] == "done"}
    ready = [
        t for t in doc["tasks"]
        if t["status"] == "pending" and all(d in done for d in t["depends_on"])
    ]
    blocked = [
        t for t in doc["tasks"]
        if t["status"] == "pending" and not all(d in done for d in t["depends_on"])
    ]
    in_progress = [t for t in doc["tasks"] if t["status"] == "in_progress"]

    if in_progress:
        print("IN PROGRESS (finish or explicitly park these first):")
        for t in in_progress:
            print(f"  {t['id']}  [{t['phase']}]  {t['title']}")
        print()

    ready.sort(key=lambda t: (phase_rank(t["phase"]), t["id"]))
    print(f"READY ({len(ready)} of {len(ready) + len(blocked)} pending):")
    for t in ready:
        print(f"  {t['id']}  [{t['phase']}/{t['estimate']}]  {t['title']}")

    print(f"\nBLOCKED ({len(blocked)}): ", end="")
    print(", ".join(f"{t['id']}→{','.join(d for d in t['depends_on'] if d not in done)}"
                    for t in blocked) or "none")
    print("\nPriority note from tasks.json:")
    print("  " + doc["$schema_note"].split("PRIORITY OVERRIDE", 1)[-1][:400])


def cmd_show(doc: dict, task_id: str) -> None:
    t = find(doc, task_id)
    print(json.dumps(t, indent=2, ensure_ascii=False))
    p = brief_path(t["id"])
    print(f"\nBrief: {p if p else '(no companion .md found)'}")
    if p:
        print("-" * 70)
        print(p.read_text())


def cmd_start(doc: dict, task_id: str) -> None:
    t = find(doc, task_id)
    done = {x["id"] for x in doc["tasks"] if x["status"] == "done"}
    unmet = [d for d in t["depends_on"] if d not in done]
    if unmet:
        print(f"WARNING: unmet dependencies: {', '.join(unmet)}\n")
    if t["status"] == "done":
        sys.exit(f"{t['id']} is already done. Use `note` to record follow-up work.")
    t["status"] = "in_progress"
    save(doc)
    print(f"{t['id']} -> in_progress  ({t['title']})")
    print(f"\nBranch from main, prefix by kind (feat/ | fix/ | chore/):")
    print(f"  git checkout main && git pull && git checkout -b feat/{slug(t['id'])}")
    print(f"\nPut {t['id']} in the branch name AND the PR title. Acceptance criteria:")
    for a in t["acceptance"]:
        print(f"  - {a}")


def cmd_done(doc: dict, task_id: str) -> None:
    t = find(doc, task_id)
    t["status"] = "done"
    save(doc)
    print(f"{t['id']} -> done  ({t['title']})")
    print("Remember the completion report (CLAUDE.md golden rule #5).")


def cmd_note(doc: dict, task_id: str, text: str) -> None:
    t = find(doc, task_id)
    entry = f"{date.today().isoformat()}: {text}"
    t["notes"] = (t["notes"].rstrip() + "\n" + entry) if t.get("notes") else entry
    save(doc)
    print(f"{t['id']} note added: {entry}")


def cmd_status(doc: dict) -> None:
    phases: dict[str, dict[str, int]] = {}
    for t in doc["tasks"]:
        phases.setdefault(t["phase"], {}).setdefault(t["status"], 0)
        phases[t["phase"]][t["status"]] += 1
    for phase in sorted(phases, key=phase_rank):
        counts = phases[phase]
        total = sum(counts.values())
        bits = ", ".join(f"{k}={v}" for k, v in sorted(counts.items()))
        print(f"  {phase:<5} {total:>3} tasks  ({bits})")


def main() -> None:
    args = sys.argv[1:]
    if not args:
        sys.exit(__doc__)
    doc = load()
    cmd, rest = args[0], args[1:]
    if cmd == "next":
        cmd_next(doc)
    elif cmd == "status":
        cmd_status(doc)
    elif cmd == "show" and rest:
        cmd_show(doc, rest[0])
    elif cmd == "start" and rest:
        cmd_start(doc, rest[0])
    elif cmd == "done" and rest:
        cmd_done(doc, rest[0])
    elif cmd == "note" and len(rest) >= 2:
        cmd_note(doc, rest[0], " ".join(rest[1:]))
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
