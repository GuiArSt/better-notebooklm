# How it was built

Better NotebookLM was built in four days by one developer and a team of AI agents. The developer made every decision; the agents
researched, planned, built and reviewed. This folder is a curated account of the method. The agents' own coordination files stay
private.

| Read | For |
|---|---|
| [decisions.md](decisions.md) | the decisions that shaped the product, and why |
| [parity-research.md](parity-research.md) | what NotebookLM does, how each function was tiered, and what shipped |
| [ai-map.md](ai-map.md) | every model call in the app: what it is given, what its prompt says, what code checks |
| [code-review-report.md](code-review-report.md) | twelve technical choices, each with what was rejected and what could still be wrong |

## The method

**Research first.** Nothing was planned until one agent had mapped NotebookLM function by function and tiered each one. The plan was
cut from that list to fit the deadline, with everything left out shown in the app as "not in this demo".

**The developer decides; agents propose.** A coordinating agent kept the plan and the list of who was working on what. When something
needed a decision, it put a numbered question to the developer: the context, the options with their cost, and a recommendation. Only the
developer's answer went into the decision log, with its date and the reason. Agreement between agents never counted as approval.

**Written briefs.** Every agent started from a written brief: the task, the model it runs on, the files it may touch, what "done" looks
like, and the proof (which test, which command). Thinking and building were split by cost: the strongest model planned and reviewed,
mid-size models built, the smallest ran commands and reported what they saw.

**One agent per feature, in its own branch.** Each building agent worked in its own copy of the repository, claimed the files it would
touch before touching them, and committed small. Two claims on one file were a conflict caught before any code was written.

**A reviewer that proves before it files.** A standing reviewing agent read every merge by its diff and assumed the builder got it
wrong. A finding counted only with proof: a failing test or a quoted line. The agent that found a problem, never the one that built the
code, judged the fix. Some of what it caught is in the code review report: overspend through a closed browser tab, invented citations, a
compressed "zip bomb" that killed a worker, and a proxy fix whose first version broke every invitation.

**A gate before release.** A change reached production through the reviewer, then the developer on a running copy. A late feature
shipped only if it was cleared by the freeze; otherwise it waited. The agents could deploy a development copy freely; production
changed only by the developer's own hand.

## Two rules, from the developer

**No implicit state (D-037).** Every AI endpoint has three layers: what the model is given, its prompt, and what code enforces around it.
Nothing the code can know is left for the model to guess: code names the language, passes the counts, lists the allowed citations, and
then checks the answer anyway. The [AI map](ai-map.md) holds every call against this rule, including the gaps still open.

**No silent errors, no unproved claims (D-038).** Every failure reaches the user, the ledger or the log, and every fallback is named.
Dead code goes, the obvious version wins, and an established library beats a hand-rolled one. A report says what was proved and what
was not. That applies to this folder too: where something is an estimate, untested, or still a
gap, it says so.
