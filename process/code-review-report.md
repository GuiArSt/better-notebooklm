# Code review report · release 1

Twelve choices a reviewer may ask about. Each says what the code does, why, what was rejected, and what could still be wrong. It is
condensed from the building agents' own entries. Every finding named here was proved by the reviewing agent (a failing test or a
quoted line) and closed by it, never by the agent that built the code. The finding ids (R1-1, A-005, ...) are the reviewer's own.

**One caveat for all twelve:** the proofs below ran on test fakes (`laravel/ai`), not against the real providers.

---

## 1 · One door for every AI call

- **What it does:** every model call (chat, summary, script, embedding, speech, image) goes through one class, `App\Ai\Router`. Before
  the provider is called, it reserves the call's highest possible cost and refuses the call if the user's cap or the app's monthly cap
  would be crossed. Afterwards it records the real cost and sends a trace to Langfuse.
- **Why:** a cap that must be remembered at every call site gets forgotten once, and that one is unmetered spend. A database lock means
  two simultaneous calls can't both squeeze under a cap.
- **Rejected:** checking the cap and writing the cost afterwards, which lets parallel calls overspend. The reviewer proved two such
  holes (R1-1, a closed browser tab mid-stream; R1-2, no output limit); both are fixed.
- **Proof:** `AiDoorTest`, `StreamAbortTest` (a real socket hang-up) and `ReviewFixesTest`, each failing on the old code.
- **Could still be wrong:** a provider may report usage in another shape, and then the estimate is billed. One global lock is fine for
  a demo and would not scale.

## 2 · Search: Postgres with pgvector, hybrid

- **What it does:** passages are stored with their embeddings in the same Postgres as everything else. A question searches by meaning
  and by exact words together, merged by rank. When the sources are small enough, they are sent whole instead.
- **Why:** one database, one backup, one container on a small server. Exact words catch names and numbers that meaning-search misses,
  in any language.
- **Rejected:** a separate vector database: another container and a second store to keep in step, for nothing needed at this size.
- **Could still be wrong:** the search settings are untuned defaults, and quality on long German PDFs is untested.

## 3 · Citations that can't be invented

- **What it does:** the model cites passages as `[P12]`. A citation survives only if that passage was actually in this prompt. Source
  text is fenced off, so a web page can't plant fake labels.
- **Why:** passage numbers are guessable. "Exists in the notebook" let invented citations through (R1-3); "was shown" doesn't.
- **Rejected:** a provider's native citations, which work for one provider only; the free tier runs on another.
- **Could still be wrong:** a valid citation proves the passage was shown, not that it supports the sentence it is attached to. The
  fence raises the cost of a prompt injection; it doesn't make one impossible.

## 4 · A web fetcher that can't be turned against the server

- **What it does:** a web-page source is fetched through a guard. It refuses private and internal addresses in 28 disguises, pins the
  DNS answer, re-checks every redirect, and counts the size after decompression.
- **Why:** without it, a user could make the server fetch its own internal services. A compressed "zip bomb" killed a worker before the
  size fix (R2-1, fixed).
- **Could still be wrong:** it rests on one small open-source package. Pages that need JavaScript return no text.

## 5 · The podcast: one model writes, ElevenLabs or Mistral speaks

- **What it does:** the text model writes a two-host script as JSON. ElevenLabs voices it for invited testers and Pro users; Mistral
  voices it for free members. The whole episode's cost is reserved before the first word.
- **Why:** the voice is the feature people judge. Reserving first means an episode can't fail halfway after being paid for (E-1).
- **Cost:** at most about $0.40 per episode on ElevenLabs, by arithmetic from a published price, not from an invoice.
- **Could still be wrong:** if ElevenLabs fails, the episode falls back to Mistral's voice; that is logged, not shown to the user.

## 6 · Deploy: one image, fenced on a shared server

- **What it does:** the image is built for x86 from a clean copy of one commit (it refuses uncommitted code) and copied to the server.
  There, each container has a hard memory cap with no swap, the app has its own network and its own Cloudflare tunnel, and no port is
  opened on the server.
- **Why:** the server is shared with other work; building on it is the biggest memory spike the project could cause there.
- **Could still be wrong:** memory under heavy load is unmeasured (an estimate of about four simultaneous streams). There is no
  database backup: it is a demo, and its data is deleted when the demo ends.

## 7 · Sign-in: Laravel's own, closed, by invitation

- **What it does:** sign-up is closed. An artisan command makes a single-use link to set a password; the link expires in 72 hours and
  only its hash is stored. Two-factor is offered to every account.
- **Why:** it runs inside the app (no extra memory, no new company holding user data). A leaked link is worthless once used.
- **Rejected:** a self-hosted identity server (over a gigabyte of memory), or a hosted vendor that would hold users' data outside the EU.
- **Could still be wrong:** there is no mail service, so links are handed out by hand. There are no "disable user" or "delete account"
  buttons yet.

## 8 · Google sign-in only for invited people; roles and tiers

- **What it does:** "Continue with Google" works only for an address that was invited or already has an account. Roles are owner,
  reviewer and member, with a free or pro tier. A Pro grant lasts 30 days and doesn't stack, and a lapsed Pro drops back to free
  everywhere, background jobs included.
- **Why:** the guest list is the only way in. A test card costs nothing, so it must never buy unlimited real spend.

## 9 · Behind Cloudflare: real visitor addresses, https everywhere

- **What it does:** the app trusts Cloudflare's visitor address only when the connection comes from inside its own network, and forces
  https on every link it makes.
- **Why:** behind the proxy every visitor looked like one address, so one attacker could lock everyone out of sign-in (R2A-1). Signed
  links were built as http, so every invitation would have failed (R2A-2).
- **The honest part:** the first fix made it worse. Every invitation returned 403, because two tests each covered half of the real path.
  The second fix was tested on the whole path, and the reviewer accepted it.
- **Could still be wrong:** it assumes the app is reachable only through the tunnel, which the deploy guarantees.

## 10 · One app: Inertia with React, not a separate frontend

- **What it does:** Laravel serves the React pages itself. A notebook arrives with its data, and there is no API to keep in step.
  Streaming chat uses plain `fetch`.
- **Why:** one origin, one sign-in, no CORS, and no Node server using a few hundred megabytes on a server where memory is scarce.
- **Rejected:** a separate Next.js frontend. Nothing in the task needed it.
- **Could still be wrong:** Inertia 3 is new. Chat history is kept in the browser, so it is lost on another device.

## 11 · The honest greeting

- **What it does:** the first screen says this is a demo for a job test, what works, what isn't in the demo, the user's budget, and
  what a podcast costs that user. Every number is read from the same config the ledger charges by.
- **Why:** a typed price goes stale. It did once (A-005), and that was fixed.
- **Could still be wrong:** the "works / not in this demo" lists are typed text, edited by hand when a feature changes.

## 12 · Untrusted text is never rendered as HTML

- **What it does:** answers, sources, notes and reports are shown as text through a small Markdown reader. There is no
  `dangerouslySetInnerHTML`. A citation chip carries the passage's exact position, so a click scrolls to it and highlights it.
- **Why:** the text is hostile by definition (web pages, uploads, model output), and React's default is safe. The reviewer found no XSS.
- **Cost:** tables and links in model output show as plain text.
- **Could still be wrong:** one future use of raw HTML breaks it, and nothing in the build stops that.
