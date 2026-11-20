# The decisions that shaped the product

The project keeps a log of every decision: the date, who made it, and why. These are the ones that shaped the product, rewritten for a
reader outside the project. The numbers are the log's own, so a reference elsewhere (such as "D-037" in the AI map) can be found here.
Only the developer's answer made a decision; the agents proposed, with options and a recommendation.

## The task

**D-001, D-010 · Build a NotebookLM clone that works.** The brief left scope and structure open, welcomed AI tools, and asked for a
repository, a live deployment and a video of ten minutes at most. Release 1 aims at feature parity where a short demo can reach it,
"good enough to start from", not perfection.

**D-002 · Research before planning.** One agent studied NotebookLM as it stood in November 2026 and cut every function into: must truly
work, can be shown but faked, worth it if time allows, and not worth it. The build followed that list ([parity-research.md](parity-research.md)).

**D-006 · Honest about being a demo.** The first screen says it is a demo built for a test, what works, what doesn't, and what the
budget allows. Every number on it is read from the same config the ledger charges by, so it can't go stale.

## Models and money

**D-005, D-037 · The tier decides the model, in config.** Free runs on Mistral (text, embeddings and voice). Pro runs text on Claude.
No request can pick a model, a provider, a voice or a prompt.

**D-014 · The podcast's voice is core.** The audio overview is NotebookLM's signature, so ElevenLabs voices it for invited testers and
Pro users. Free users hear Mistral's voice, which keeps the cost capped.

**D-004, D-007, D-029 · Pricing that takes no real money.** Three tiers: Free (capped), Pro (Claude and ElevenLabs, shown at cost × 1.5)
and Own key, shown as "not in this demo". Pro is bought through Stripe's checkout in test mode: the whole flow runs and no money moves.
A test purchase gives 30 days of Pro with a capped allowance, so a free test card can never buy unlimited real spend.

**D-008 · Tracing from the first commit.** Every model call is traced in Langfuse, and prompts can be managed there, with the files in
the repository as the fallback.

## Stack and hosting

**D-012 · Laravel 13 with Inertia and React 19, Postgres with pgvector, a database queue.** One app, one origin, one database. Sign-in is
Laravel's own (Fortify, Socialite), not an identity vendor or a separate server.

**D-016 · A Docker container on a shared Hetzner server, fenced.** Its own compose project and network, a hard memory limit on every
container, no port opened on the server, and traffic only through a Cloudflare tunnel.

**D-012, D-022, D-024 · Closed sign-up.** Users join by a single-use invitation link (72 hours, only its hash stored) or by Google for an
address already invited. The demo sends no email: invitation links are handed out by hand. Two-factor is offered, not required.

## The rules for AI and for code

**D-037 · Every AI endpoint has three layers.** *Empower:* what the model is given. *Guide:* its prompt, a plain file in
`src/resources/prompts/`. *Enforce:* what code decides and checks around it: the cap, the ledger, the trace, validation, citations,
language. The rule: **nothing the code can know is left for the model to guess.** If a prompt is about to say "answer in the language of
the text", code names the language instead, and checks the answer anyway. The [AI map](ai-map.md) holds every call against this rule.

**D-038 · Rules of thumb for code.** No implicit state. No silent errors: every failure reaches the user, the ledger or the log, and a
fallback is named, never hidden. Prune dead code and one-caller abstractions. Write the short, obvious version. Prefer an established
library to hand-rolled code. Don't break things: each cleanup is a small commit with a test, and anything touching money, sign-in or data
waits until after the hand-in.

## Features added after the first plan

The first plan shipped sources, cited chat, notes, the audio overview and reports, with the rest greyed out. As the core held, these
joined, each behind the same gate: if it wasn't reviewed and cleared by the freeze, it would not ship.

- **D-033 · Quiz:** 5 to 8 multiple-choice questions, each explanation citing a passage the model was shown.
- **D-034 · Slide deck:** the model never writes layout. It picks one of 7 fixed templates per slide and fills its fields, which have
  hard length limits; a deck that fails the contract gets one repair call, then fails cleanly.
- **D-035 · Customize before generating:** language, sources, a focus, the kind's own option, and the estimated cost.
- **D-039 · Podcast formats and personas:** Deep Dive, Brief, Critique and Debate for everyone; for Pro, one host can be one of four
  archetypes. None imitates a real living person, there is no voice cloning, and a persona never invents a fact.
- **D-040 · Heavy sources:** a large source is read with a warning of the time and the cost; one over the limit is refused with the
  limit stated, never cut silently.
- **D-044 · A real mind map:** a horizontal, expandable tree drawn by a maintained library, its nodes still citing their passages.
- **D-045 · Infographics:** the text model writes a short cited brief; only that brief, never the sources, goes to OpenAI's image
  model; the citations sit under the image. It was held back in production until OpenAI's data-processing agreement was confirmed.
- **D-032 · Notebook icons:** a small generated image per notebook, through the same Router, off unless switched on; any failure keeps
  the emoji.

## Hardening at the end

**D-046 · Prompt security.** Everything untrusted reaches a model inside a CDATA block that it cannot close, labelled by where it came
from, and each prompt says before and after the data that it is data, never an instruction. Escaping that fails throws instead of
sending the raw text.

**D-047 · No failures from our own limits.** For the demo, output caps and budgets were raised so a long answer or a careful model is
not cut off. The Router's cap, ledger and trace stay on every call; only the numbers changed.

**D-048 · Testers get the Pro models by role,** not by a manual step after each invitation, which would be easy to forget.

## Privacy

**D-019, D-020 · A privacy page and terms of use.** The app lists what it processes and with whom, and its terms say plainly that it is a
demo, not a service: no warranty, AI can be wrong, upload nothing personal, test payments are not real, your content is deleted on
request or when the demo ends. (The terms then retell all of this as a pact with an eldritch institution. A reader who gets that far
has earned it.)
