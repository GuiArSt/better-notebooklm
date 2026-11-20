# Demo deployment: steps

For the shared box. The app gets its own folder, its own networks and its own memory limits (1.6 GB in all). It publishes **no host
port**: its own small tunnel service reaches it over the project's own network. Nothing here touches another project.

**No code is built on the box.** The images are built on your Mac from a clean copy of one commit and shipped; the box only loads
and runs them, so no Composer, npm or Vite ever runs there.

## 1. On your Mac: build the images

The box is x86 (`linux/amd64`). On an Apple Silicon Mac the build runs under emulation: the first build takes about 15 to 25
minutes, a rebuild after a code change about 2 to 5. The compiler crashes now and then under emulation; the Dockerfile retries
those steps by itself, so "retry 1" in the output is normal.

Commit everything first. The script **refuses to build if a tracked file has uncommitted changes**, and builds from a clean copy of
the commit (`git archive HEAD`), so the image is exactly the commit it is tagged with:

```bash
deploy/build-images.sh
```

It makes `application-test/app:<tag>` and `application-test/media:<tag>` (the same app plus ffmpeg); the tag is the short commit id,
printed at the end. The images are about 1.1 GB and 1.7 GB on disk and share most of their layers.

## 2. Send them to the box

```bash
export IMAGE_TAG=<the tag it printed>
docker save application-test/app:$IMAGE_TAG application-test/media:$IMAGE_TAG | gzip > images-$IMAGE_TAG.tar.gz
scp images-$IMAGE_TAG.tar.gz <the box>:application-test/
ssh <the box> "cd application-test && gunzip -c images-$IMAGE_TAG.tar.gz | docker load"
```

(Make the folder `application-test` on the box first, as in step 4. If you would rather use a registry, push the two images there
and pull them on the box; the tag is what matters.) **Leave the `.tar.gz` file in that folder on the box**: it is the rollback.

## 3. Create the tunnel (once)

In Cloudflare (Zero Trust, Networks, Tunnels) create a **new** tunnel for this app, never reuse another project's. Add a public
hostname `betternotebookllm.tartarus-vault.com` with the service `HTTP` and the URL `app:80`. Copy the tunnel token.

## 4. Put the compose files on the box

```bash
mkdir -p ~/application-test && cd ~/application-test
# copy these three files here: deploy/compose.yaml, deploy/compose.demo.yaml, deploy/.env.example
cp .env.example .env
```

## 5. Write your own `.env`

Edit `.env` and fill in, with **fresh values made for this app, never copied from another project**:

- `IMAGE_TAG`: the tag from step 1
- `APP_URL`: `https://betternotebookllm.tartarus-vault.com`
- `APP_KEY`: make one with `echo "base64:$(openssl rand -base64 32)"`
- `POSTGRES_PASSWORD` and `DB_PASSWORD`: the same new password in both (`openssl rand -hex 16`)
- `CLOUDFLARE_TUNNEL_TOKEN`: the token from step 3
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`: the Google sign-in client you created; `GOOGLE_REDIRECT_URI`:
  `https://betternotebookllm.tartarus-vault.com/auth/google/callback`
- `MISTRAL_API_KEY`, `ELEVENLABS_API_KEY`, `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`: the keys you created for this app
- leave `ELEVENLABS_VOICE_A` and `ELEVENLABS_VOICE_B` empty unless you want other voices

`.env` stays on the box. It is never committed and never sent anywhere.

## 6. Start it

```bash
docker compose -p application-test -f compose.yaml -f compose.demo.yaml up -d
```

The first start pulls the small tunnel image from Docker Hub and creates the database tables.

## 7. Make yourself the first user

```bash
docker compose -p application-test -f compose.yaml -f compose.demo.yaml exec app php artisan invite <your email> owner
```

It prints a single-use link (72 hours): open it and set your password. No mail is sent.

Invite testers with `php artisan invite <email> reviewer`; it prints the link to send yourself.

## 8. Check that it is done

```bash
docker compose -p application-test ps
curl -fsS https://betternotebookllm.tartarus-vault.com/up
```

`ps` shows four services (`app`, `db`, `worker-default`, `cloudflared`), with `app` and `db` healthy. `curl` prints nothing wrong and
exits 0. Each service is capped in memory (`docker stats --no-stream` shows the limits) and none can swap.

## Update

Build and send the new images (steps 1 and 2), then on the box set the new `IMAGE_TAG` in `.env` and run step 6 again.
**Keep the previous images and their `.tar.gz` files on the box** (do not run `docker image prune`, do not delete the archives).

## Roll back

On the box, in the app folder, put the previous tag back in `.env` (`IMAGE_TAG=<the previous tag>`). If the previous images are
gone from Docker, load them again from the archive you kept:

```bash
gunzip -c images-<the previous tag>.tar.gz | docker load
docker compose -p application-test -f compose.yaml -f compose.demo.yaml up -d
```

The data is kept. To stop it without losing anything: `docker compose -p application-test -f compose.yaml -f compose.demo.yaml down`.

## Remove it completely

```bash
docker compose -p application-test -f compose.yaml -f compose.demo.yaml down -v
docker rmi $(docker images -q 'application-test/*')
cd .. && rm -rf application-test
```

Then delete the tunnel (and its hostname `betternotebookllm.tartarus-vault.com`) in Cloudflare. `down -v` deletes the database and the
uploaded files.
