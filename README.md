# atoll-benchmarks

Reproduzierbares Benchmark-Harness fuer:

- atoll
- atoll (static profile)
- atoll (minimal core-only profile)
- WordPress
- Kirby
- Grav

## Warum separates Repo?

Benchmark-Infrastruktur (Docker-Stacks, Vergleichsskripte, Ergebnisartefakte) ist bewusst vom CMS-Core getrennt.
Dadurch bleibt `atoll-core` schlank und der Vergleich reproduzierbar in einem dedizierten Projekt.

## Voraussetzungen

- Docker + Docker Compose
- PHP 8.2+
- Composer

## Setup

```bash
composer install
./bin/bench up
./bin/bench check
```

`bench check` sollte fuer alle sechs Ziele `200` liefern.
Der Check wartet automatisch mit Retries (Default: 45 Versuche alle 2s), bis alle Targets stabil sind.

## Benchmark laufen lassen

```bash
./bin/bench run --rounds=3
```

Optional:

```bash
./bin/bench run --only=atoll-home --rounds=5
./bin/bench run --max-error-rate=1
```

Sicherheitslogik im Runner:

- Lauf wird als `error` markiert, wenn kein `2xx`-Erfolgscode gemessen wurde.
- Lauf wird als `error` markiert, wenn die Fehlerrate ueber `--max-error-rate` liegt.
- Lauf wird als `error` markiert, sobald HTTP `429` erkannt wird (Rate-Limit verfälscht Messung).
- `bench run` fuehrt vor dem Lauf automatisch den Health-Check aus und bricht bei instabilem Stack ab.

Report erzeugen:

```bash
./bin/bench report --out=benchmarks/results/latest.md
```

## Targets

Standard-Targets: `benchmarks/targets.yaml`

- atoll: `http://127.0.0.1:8080/`
- atoll static profile: `http://127.0.0.1:8084/`
- atoll minimal core-only profile: `http://127.0.0.1:8085/`
- WordPress: `http://127.0.0.1:8081/`
- Kirby: `http://127.0.0.1:8082/`
- Grav: `http://127.0.0.1:8083/`

`127.0.0.1` ist absichtlich fix gesetzt, um lokale IPv6-Dev-Server auf `localhost` (z. B. `::1`) nicht versehentlich zu benchmarken.

## Fairness-Hinweise

- Gleiche Seitenarten vergleichen (z. B. Homepage vs. Homepage).
- Alle Systeme vorab aufwaermen.
- Gleiche Lastparameter (`requests`, `concurrency`, `rounds`) verwenden.
- Der atoll-Docker-Service setzt im Benchmark-Stack ein hohes Rate-Limit, um versehentliche Drosselung zu vermeiden.
- Der atoll-Docker-Service deaktiviert zusaetzlich `security.rate_limit.enabled` fuer den Benchmark-Container, um I/O-Overhead der Drosselung im Public-Read-Test auszuschliessen.
- Das Ziel `atoll-static` nutzt zusaetzlich `security.session.enabled=false`, um einen reinen Static-Site-Betrieb ohne Session-Overhead abzubilden.
- Das Ziel `atoll-minimal` bildet einen Core-only Read-Path ab (`security.session.enabled=false`, Plugin-Verzeichnis entfernt).
- Wenn dennoch `429` auftritt, gilt der Lauf als ungueltig und muss wiederholt werden.
- WordPress wird im Stack automatisch per `wp-cli` initialisiert, damit die Homepage nicht auf Setup/Redirect landet.

## CI

Das Repo enthaelt einen GitHub-Workflow `.github/workflows/benchmarks.yml`:

- PRs: 1 Runde (schneller Smoke-Benchmark)
- `main`: 3 Runden
- Trigger nur bei benchmark-relevanten Dateiaenderungen (`docker/**`, `tools/**`, `bin/**`, `benchmarks/targets.yaml`, Workflow/Composer-Dateien)
- Auf `main` wird zusaetzlich ein oeffentliches Dashboard nach GitHub Pages deployed: `https://atoll-cms.github.io/atoll-benchmarks/`
  - JSON Snapshot: `https://atoll-cms.github.io/atoll-benchmarks/latest.json`
