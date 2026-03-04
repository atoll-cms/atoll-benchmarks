# atoll-benchmarks

Reproduzierbares Benchmark-Harness fuer:

- atoll
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

`bench check` sollte fuer alle vier Ziele `200` liefern.

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

- Lauf wird als `error` markiert, wenn kein Erfolgscode gemessen wurde.
- Lauf wird als `error` markiert, wenn die Fehlerrate ueber `--max-error-rate` liegt.
- Lauf wird als `error` markiert, sobald HTTP `429` erkannt wird (Rate-Limit verfälscht Messung).

Report erzeugen:

```bash
./bin/bench report --out=benchmarks/results/latest.md
```

## Targets

Standard-Targets: `benchmarks/targets.yaml`

- atoll: `http://localhost:8080/`
- WordPress: `http://localhost:8081/`
- Kirby: `http://localhost:8082/`
- Grav: `http://localhost:8083/`

## Fairness-Hinweise

- Gleiche Seitenarten vergleichen (z. B. Homepage vs. Homepage).
- Alle Systeme vorab aufwaermen.
- Gleiche Lastparameter (`requests`, `concurrency`, `rounds`) verwenden.
- Der atoll-Docker-Service setzt im Benchmark-Stack ein hohes Rate-Limit, um versehentliche Drosselung zu vermeiden.
- Wenn dennoch `429` auftritt, gilt der Lauf als ungueltig und muss wiederholt werden.
