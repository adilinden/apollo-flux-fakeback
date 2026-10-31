# apollo-flux stack

Staging / PoC stand-in backend for `web-proxy1`. Two containers:

| Container | Image | Published | Purpose |
|-----------|-------|-----------|---------|
| `flux-web` | `php:8.3-apache` | host 80 and 443 (self-signed) | Static page at `/`, request/proxy header dump at `/info` (not linked) |
| `flux-ssh` | `lscr.io/linuxserver/openssh-server` | host 2200 (→ 2222 in container) | Shell for the SSH port-forward test |

## Layout

```
docker-compose.yml
.env.example              copy to .env to change defaults
make-cert.sh              generates the self-signed cert for :443 (run once)
web/html/index.html       static page
web/private/info.php      header dump, served at /info (outside the docroot)
web/apache/zz-flux.conf   Alias /info, ServerTokens Prod (zz- so it loads after Debian's security.conf)
web/php/flux.ini          expose_php = Off
web/apache/zz-ssl.conf    loads mod_ssl, :443 vhost (not enabled in the stock image)
web/tls/                  flux.crt / flux.key land here (generated, never commit the key)
ssh/pubkeys/              put public keys here (one per file; nothing else)
```

## Start

```bash
cp .env.example .env            # optional
./make-cert.sh                  # self-signed cert for :443, once (needs openssl on the host)
cp ~/.ssh/id_ed25519.pub ssh/pubkeys/me.pub
docker compose up -d
docker compose ps
docker compose logs -f
```

Every file in `ssh/pubkeys/` is read as an authorized key, so do not put a README
or any other file in that directory.

## Web tests

```bash
curl -sI http://10.33.89.50/                  # direct to the backend
curl -s  http://10.33.89.50/info | head       # direct: no X-Forwarded-* expected
curl -sI https://apollo-flux.example.com/     # through the proxy
curl -s  https://apollo-flux.example.com/info # through the proxy
```

Through the proxy, `/info` should show:

- `REMOTE_ADDR` = `10.33.89.100` (web-proxy1 the backend sees the proxy, not the client)
- `X-Forwarded-For` = your public IP
- `X-Forwarded-Proto` = `https`, `X-Forwarded-Host` = `apollo-flux.example.com`
- `HTTP_HOST` = `apollo-flux.example.com` (Caddy passes the original Host)
- `REQUEST_SCHEME` = `http` and `HTTPS env` = `(unset)`: the hop to Apache is plain HTTP.
  An app that builds URLs from this would emit `http://`; this is why backends
  need `X-Forwarded-Proto` handling (runbook 4.8).

Open `/info` in a browser only after the proxy path works; the page hides cookie and
Authorization values and shows only their presence.

## TLS (re-encrypt test)

`:443` serves the same site with a 10-year self-signed cert (CN/SAN `apollo-flux.knet.ca`).
Apache will not start until `./make-cert.sh` has created `web/tls/flux.crt` and `flux.key`.

```bash
curl -skI https://10.33.89.50/
curl -sk  https://10.33.89.50/info | grep -E 'HTTPS env|REQUEST_SCHEME'
echo | openssl s_client -connect 10.33.89.50:443 -servername apollo-flux.knet.ca 2>/dev/null \
  | openssl x509 -noout -subject -dates
```

Over `:443`, `/info` should show `REQUEST_SCHEME` = `https` and `HTTPS env` = `on`. That is the
difference from the `:80` hop, and it is why an app that insists on HTTPS keeps working when the
proxy re-encrypts instead of forwarding plain HTTP.

To test the proxy -> backend re-encrypt path, copy only `web/tls/flux.crt` to web-proxy1
(for example `/etc/caddy/backend-certs/apollo-flux.crt`, readable by `caddy`) and change the
apollo-flux site block. Option names are from memory; check them against the Caddy 2.11 docs:

```
apollo-flux.knet.ca {
	reverse_proxy https://10.33.89.50:443 {
		transport http {
			tls_server_name apollo-flux.knet.ca
			tls_trust_pool file /etc/caddy/backend-certs/apollo-flux.crt
		}
	}
}
```

Simpler, but it stops authenticating the backend: replace the `tls_trust_pool` line with
`tls_insecure_skip_verify`. The FortiGate policy 1302 -> apollo-flux needs TCP 443 added.

## Header exposure check

```bash
curl -sI http://10.33.89.50/ | grep -iE '^(server|x-powered-by)'
```

Expect `Server: Apache` with no version and no `X-Powered-By`. (Through the proxy a
`Via: 1.1 Caddy` response header is also present; Caddy adds it.)

## SSH tests

Key (default):

```bash
ssh -p 2200 -i ~/.ssh/id_ed25519 fluxtest@10.33.89.50          # direct
ssh -p 2200 -i ~/.ssh/id_ed25519 fluxtest@79.165.220.88        # through the proxy
```

Password: in `.env` set `SSH_PASSWORD_ACCESS=true` and `SSH_PASSWORD=<something>`, then
`docker compose up -d` again.

Note on the FortiGate VIP: the container is published on host port **2200**, so the
apollo-flux SSH VIP must map `2200 → 2200`. The host's own sshd occupies port 22, so a
`2200 → 22` mapping would reach the Docker host, not this container.

## Notes

- Docker publishes ports through its own firewall rules, which bypass firewalld. Only the
  FortiGate policy limits who can reach host ports 80 and 2200.
- Pin the image tags once the stack is proven; `latest` and `8.3-apache` move.
- Stop and remove: `docker compose down` (add `-v` to delete the ssh host keys volume).
