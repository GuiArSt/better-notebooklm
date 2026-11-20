# Parity research: what NotebookLM does, and what we built

Before any plan, one agent studied NotebookLM as it stood on 17 November 2026 (by then Google had renamed it Gemini Notebook). It
browsed the product, read Google's help centre and announcements, and marked every fact by how it knew it: seen in the browser, stated
by Google, reported by the press, or inferred. Then it listed every function and gave each one a tier:

- **core:** must truly work in the demo;
- **fake:** shown, but reduced or faked, and labelled as such;
- **stretch:** worth it if time allows;
- **skip:** cool, but not worth it for this demo.

**The count:** 56 functions: 17 core, 7 fake, 18 stretch, 14 skip.

## The cut, and what happened to it

| Area | The research said | What shipped |
|---|---|---|
| Accounts, notebooks, three-panel layout | core | Yes: invitation and Google sign-in, notebooks, sources · chat · studio |
| Sources: file upload, web page, pasted text | core | PDF and text files, web pages (through a request-forgery guard), pasted text |
| Source checkboxes, viewer with a summary | core | Yes |
| Notebook summary with suggested questions | core | Yes |
| Grounded, streaming chat | core | Yes |
| Citations that hover and jump to the passage | core, "worth the most care" | Yes, and validated in code against what the model was shown |
| Chat history | core | Partly: kept in the browser, not on the server |
| Notes, "Save to note" | core | Yes; a note can be used as a source |
| Studio: audio overview, mind map, reports | core | Yes |
| Audio formats, length, language | stretch | Yes: four formats, three lengths, a language choice; plus personas for Pro |
| Quiz, slide deck | stretch | Yes |
| Light and dark theme | stretch | Yes |
| Usage meter, greyed tiles, a featured demo notebook, a privacy note | fake | Yes |
| Pricing | open at the time | Free, Pro through a Stripe test checkout, and "Own key" shown as not in this demo |
| Infographic | stretch | Built (Pro): a cited brief, then one image, then a review of the image against the brief; behind a config switch |
| Flashcards, data table, video, sites | stretch | Greyed: "not in this demo" |
| YouTube, audio and image sources | stretch | Greyed |
| Web research (Fast and Deep Research) | stretch | Greyed |
| Sharing between users | stretch | Not built |
| Google Drive, agentic chat, live voice, mobile apps, ecosystem integrations | skip | Not built; Drive is shown greyed |

## What the research changed

- **Citations got the most care.** The research named them the proof of grounding. That is why a citation is checked against the
  exact passages in the prompt, not merely against the notebook (see [code-review-report.md](code-review-report.md), entry 3).
- **The podcast is the centrepiece.** It is NotebookLM's signature, so its voice was made core work rather than a stretch.
- **Greyed tiles over gaps.** A Studio with missing tiles looks broken; a greyed tile that says "not in this demo" looks honest.
- **Everything Google-ecosystem, agentic, live-voice or video-model based was skipped** as a different product, or as cost with no
  value in a short demo.
