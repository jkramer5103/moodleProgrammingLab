# Moodle Programming Lab (`mod_mpl`)

Eine Moodle-Aktivität für Python-Aufgaben – als Ersatz für VPL, ohne dessen Schmerzen:

| VPL | Programming Lab |
|---|---|
| Browser verbindet sich direkt mit dem Jail-Server → braucht öffentlich vertrauenswürdiges TLS-Zertifikat, sonst Zertifikatswarnung | **Browser spricht nur mit Moodle.** Nur der Moodle-Server erreicht den Runner im internen Netz. Kein Zertifikatsproblem. |
| Bewertung über `vpl_evaluate.sh`/eigene Grader-Skripte | **Tests aus Bausteinen** im Formular – Konsolenprogramm, Funktion, Klasse, Datei, matplotlib-Diagramm |
| Grader liegt in derselben Laufzeit wie der Schülercode und kann z. B. über `os` manipuliert werden | **Grader läuft getrennt.** Schülercode, Musterlösung und Prüfskripte laufen jeweils in eigener Sandbox; die Note wird im Runner berechnet. |
| Feste Testfälle → Lösungen lassen sich hart codieren | **Zufallseingaben**, Sollwerte rechnet die Musterlösung aus |

```
 Browser (Schüler/in)                Moodle-Server                      Runner-Server (Schulnetz)
 ┌──────────────────────┐  HTTPS   ┌──────────────────────┐  intern  ┌──────────────────────────────────┐
 │ CodeMirror-Editor    │ ───────▶ │ mod_mpl (PHP)        │ ───────▶ │ FastAPI (Bearer-Secret)           │
 │ Konsole · Ergebnisse │ ◀─────── │ Abgaben · Noten      │ ◀─────── │  ├─ Grader (vergleicht, rechnet) │
 └──────────────────────┘  AJAX    │ Gradebook            │  JSON    │  └─ Docker-Sandboxes             │
                                   └──────────────────────┘          │     Schülercode │ Musterlösung   │
                                                                     └──────────────────────────────────┘
```

## Inhalt des Repos

| Ordner | Was |
|---|---|
| `mpl/` | Das Moodle-Plugin (Aktivitätsmodul `mod_mpl`, entwickelt und getestet mit Moodle 4.4.12+) |
| `runner/` | Ausführungsserver: FastAPI-App (`app/`), Sandbox-Image mit Harness (`sandbox/`), `docker-compose.yml` für den Betrieb, Integrationstests (`tests/`) |
| `dev/` | Lokale Testumgebung: Moodle 4.4 + PostgreSQL + Runner, Demo-Kurs, Smoke- und Browsertests |
| `tooling/` | Build der JavaScript-Module (CodeMirror 6 + Plugin-Module) und ZIP-Paketierung |

---

## Installation (Produktion)

### 1. Runner aufsetzen

Auf einem Linux-Server im Schulnetz mit Docker (Compose v2). Der Moodle-Server muss ihn erreichen, Schüler-Rechner nicht.

```bash
cd runner
cp .env.example .env
# Geheimnis erzeugen und in .env bei MPL_RUNNER_SECRET eintragen:
openssl rand -hex 32
docker compose build
docker compose up -d
curl http://localhost:8000/v1/health     # → {"ok":true,"warm":4,"workers":…}
```

* Port 8000 nur für den Moodle-Server freigeben (Firewall), oder `MPL_RUNNER_BIND=10.0.0.5:8000` setzen.
* Optional TLS: einen Reverse-Proxy (Caddy/nginx) davor stellen. Weil nur Moodle den Runner anspricht, reicht ein selbst signiertes Zertifikat (in den Plugin-Einstellungen „TLS-Zertifikat prüfen“ abschalten). Die Browser der Schüler/innen sind davon nie betroffen.
* Der Runner braucht Zugriff auf den Docker-Socket, um Sandboxes zu starten. Ein Rechner, auf dem nur der Runner läuft, ist daher die sauberste Lösung.
* Stärkere Isolation (optional): [gVisor](https://gvisor.dev) installieren und `MPL_DOCKER_RUNTIME=runsc` setzen.

### 2. Plugin installieren

```bash
tooling/package.sh          # → dist/mod_mpl-1.2.0.zip   (oder: Ordner mpl/ nach <moodle>/mod/mpl kopieren)
```

In Moodle: *Website-Administration › Plugins › Plugin installieren* → ZIP hochladen → Upgrade durchführen.

### 3. Verbinden

*Website-Administration › Plugins › Aktivitäten › Programming Lab*:

* **Runner-URL**: z. B. `http://10.0.0.5:8000`
* **Gemeinsames Geheimnis**: derselbe Wert wie `MPL_RUNNER_SECRET`
* Auf **„Verbindung testen“** klicken – dabei wird eine kleine Testaufgabe mit Zufallseingaben bewertet.

---

## Für Lehrkräfte

Aktivität „Programming Lab“ hinzufügen. Im Formular:

1. **Startcode** (optional) – steht beim ersten Öffnen im Editor.
2. **Musterlösung** – wird nie an Schüler/innen geschickt. Sie berechnet die erwarteten Ergebnisse.
3. **Tests & Bewertung** – Tests per Klick hinzufügen:

| Testtyp | Was passiert |
|---|---|
| **Konsolenprogramm** | Programm starten, Eingaben zeilenweise über `input()` einspeisen, `print()`-Ausgabe vergleichen |
| **Funktion** | `funktion(arg1, arg2, …)` aufrufen und Rückgabewert vergleichen (`if __name__ == "__main__":` wird nicht ausgeführt) |
| **Klasse** | Objekt erzeugen, Methoden nacheinander aufrufen, Rückgabe / Attribut / `str(obj)` prüfen |
| **Datei** | Programm starten, Inhalt einer erzeugten Datei vergleichen |
| **Code-Prüfung** | Quelltext statisch prüfen (siehe unten), z. B. gegen hart codierte Lösungen |
| **Diagramm** | matplotlib-Diagramm mit dem der Musterlösung vergleichen: Typ, Datenwerte (mit Toleranz), Titel, Achsenbeschriftungen, Legende, Anzahl Reihen – Punkte je Kriterium; beide Bilder werden angezeigt |

**Eingaben/Argumente** sind pro Eintrag wählbar: fester Wert, zufällige Ganzzahl/Kommazahl/Text/Liste, zufällig aus einer Auswahl, True/False. Bei Zufallswerten läuft der Test mehrfach („Zufallsdurchläufe“), jedes Mal mit neuen Werten – hart codierte Lösungen fallen durch.

**Erwartetes Ergebnis**: aus der Musterlösung berechnen · fester Wert · keins (dann zählen nur Bedingungen/Prüfskript – oder einfach „läuft ohne Fehler“).

**Vergleich** (die wichtigsten):

* *Zeilenweise* – Standard, Leerzeichen am Zeilenende egal
* *Nur die Zahlen vergleichen* – Format egal: `[2, 3, 5]` = `2 3 5` = eine Zahl pro Zeile (ideal für „gib alle Primzahlen aus“)
* *Ausgabe enthält das Erwartete* – wenn `input("Zahl: ")` Text mit ausgibt
* *Reihenfolge egal*, *Leerzeichen egal*, *Regulärer Ausdruck*, bei Werten *Kommazahlen mit Toleranz*, *Als Text vergleichen*

**Zusätzliche Bedingungen**: nicht leer, Typ, sortiert, keine Duplikate, Länge, Wertebereich, enthält / enthält nicht, Regex.

**Prüfskript** (Erweitert, optional) für Sonderfälle – läuft in einer eigenen Sandbox, Schüler/innen sehen es nie:

```python
def check(result, expected, inputs):
    # result: Rückgabewert bzw. Ausgabe der Schülerlösung, inputs: Liste der Eingaben
    if sorted(inputs[0]) != result:
        return False, "Die Elemente stimmen nicht"
    return True                      # oder eine Zahl 0..1 für Teilpunkte
```

**Gegen Abschreiben/Hartcodieren:**

* **Erwartetes Ergebnis zeigen** (pro Test): *automatisch* zeigt es nur bei Zufallseingaben – bei festen Eingaben wäre es immer gleich und würde die Lösung verraten. Alternativ *immer* oder *nie*.
* **Code-Prüfung** (eigener Testtyp): prüft den Quelltext, ohne ihn auszuführen – „verwendet eine Schleife / Funktion / Rekursion …“, „höchstens N Zahlen im Code“, „ruft `sorted()` (nicht) auf“, „importiert (nicht) …“, „höchstens N Zeilen“, Regex.
* **Pflichttest**: Wird er nicht bestanden, gibt es 0 Punkte für die ganze Aufgabe. Beispiel „Primzahlen bis 100“: Code-Prüfung „verwendet eine Schleife“ + „höchstens 10 Zahlen im Code“ als Pflichttest – eine abgetippte Primzahlliste bringt dann 0 Punkte.
* Am wirksamsten bleiben Aufgaben mit Eingaben (z. B. „Primzahlen bis n“ mit zufälligem n).

**Sichtbarkeit** je Test: *sichtbar* (Eingabe, erwartet, bekommen, voller Traceback) · *nur Name* (bestanden ja/nein) · *versteckt* (nur in der Gesamtsumme bei der Abgabe, nicht beim Prüfen).

Unter dem Builder: **„Musterlösung testen“** (sollte volle Punktzahl geben) und **„Andere Lösung testen“** (prüfen, ob falsche Lösungen wirklich durchfallen).

**Ablauf** (pro Aktivität): „Ausführen“ (interaktive Konsole) an/aus, „Prüfen“ (sichtbare Tests, unbewertet) an/aus, optional *jedes Prüfen zählt als Abgabe*, maximale Abgaben, beste/letzte Abgabe zählt, Fälligkeitsdatum, letzte Abgabemöglichkeit, Zeit- und Speicherlimit.

**Abgaben**: Reiter „Abgaben“ in der Aktivität – Übersicht aller Schüler/innen, jede Abgabe mit Code und allen Testdetails. Noten landen automatisch in der Bewertungsübersicht und können dort manuell überschrieben werden.

## KI-Hinweise (optional)

Bei einem **fehlgeschlagenen, sichtbaren Test** („Hilf mir“) oder einem **Fehler in der Konsole** („Fehler erklären“) können Schüler/innen einen KI-Hinweis anfordern:

* ein Denkanstoß in 3–6 Sätzen, **ohne Lösungscode**, auf Deutsch, per du; bis zu 3 Rückfragen pro Hinweis sind gratis
* keine Stufen: jeder Hinweis ist gleich konkret, die KI kennt ihre früheren Hinweise, wiederholt sich nicht und wird nicht konkreter
* **Kosten** (pro Aktivität einstellbar): 1. Hinweis gratis, jeder weitere −5 % der Note dieser Aufgabe, maximal −35 % – dann gibt es keine Hinweise mehr. Vor jedem kostenpflichtigen Hinweis wird bestätigt. Der Abzug gilt für alle Versuche und steht im Gradebook.
* bei „nur Name“- oder versteckten Tests gibt es **keine** KI-Hilfe; die KI bekommt nie mehr zu sehen als die Schüler/innen (plus Musterlösung, mit der Anweisung, sie nicht zu verraten)
* Lehrkräfte sehen alle Hinweise (Reiter „Abgaben“ → Spalte „KI-Hinweise“); das wird den Schüler/innen angezeigt. Für Lehrkräfte sind Hinweise kostenlos.
* pro Aktivität einschaltbar (Standard: aus – für Klassenarbeiten aus lassen)

**Einrichtung**: OpenRouter-Key in `runner/.env` als `MPL_AI_API_KEY` eintragen, `docker compose up -d`. Standardmodell: `MPL_AI_MODEL` (jede OpenAI-kompatible API über `MPL_AI_BASE_URL`). Der Runner verlangt bei OpenRouter nur Anbieter, die die Daten **nicht zum Training** verwenden (`data_collection: deny`), und schickt keine Namen oder Nutzerkennungen. Nur der Runner braucht dafür Internetzugang, die Sandboxes nie. **Vor dem Einsatz das OK der Schule/des Datenschutzbeauftragten einholen** – der Code verlässt dabei das Schulnetz.

Modelle vergleichen: `MPL_AI_API_KEY=… python3 runner/tests/hint_eval.py modell1 modell2` (15 typische Schülerfehler inkl. Jailbreak-Versuch; markiert Codeblöcke und Fragmente der Musterlösung).

## Aufgaben-KI für Lehrkräfte (optional)

In den Einstellungen jeder Aktivität gibt es einen **Aufgaben-Assistenten**: Die Lehrkraft beschreibt in eigenen Worten, was sie möchte (Klassenstufe, Vorwissen, Thema). Die KI füllt dann Name, Aufgabentext, Startcode, Musterlösung und Tests aus und auf Wunsch auch Einstellungen wie „keine KI-Hilfe, nur eine Abgabe“.

* **Weiterschreiben**: Mit Nachrichten wie „zusätzlich den Durchschnitt ausgeben“ lässt sich die Aufgabe ändern. Von Hand geänderte Felder sieht die KI und übernimmt sie. Das funktioniert auch bei bestehenden Aufgaben.
* **Automatisch geprüft**: Vor dem Einfüllen muss die Musterlösung die volle Punktzahl erreichen, und typische Fehllösungen (inkl. Hartcodieren) müssen durchfallen. Sonst bekommt die KI die Fehler zurück und bessert nach (bis zu `MPL_AI_AUTHOR_REPAIRS` Runden).
* Gespeichert wird erst mit dem Formular. Der Chatverlauf bleibt pro Aktivität erhalten.
* Läuft als Hintergrund-Job mit Fortschrittsanzeige, typische Dauer 30–120 s, Kosten etwa 0,1 Cent pro Aufgabe.
* Modell: `MPL_AI_AUTHOR_MODEL` (Standard `z-ai/glm-5.3-flash`, mit Reasoning `MPL_AI_AUTHOR_REASONING=medium`). Für Schülerhinweise bleibt `MPL_AI_MODEL` zuständig.
* Auf den Lehrkraft-Seiten steht ein Support-Hinweis (Website-Administration → Plugins → MPL → „Support-Adresse“, Standard `info@jkramertech.com`).

## Für Schüler/innen

* Editor mit Syntaxhervorhebung, Auto-Einrückung und automatischem Speichern
* **Ausführen** (Strg+Enter): interaktive Konsole – `input()` funktioniert, Diagramme erscheinen direkt in der Konsole
* **Prüfen** (Strg+Umschalt+Enter): Tests laufen, pro Test Eingabe / erwartet / bekommen mit markierten Unterschieden, bei Fehlern der volle Traceback
* **Abgeben**: bewerteter Versuch; „Meine Abgaben“ zeigt frühere Versuche, deren Ergebnisse und erlaubt, den Code wieder zu laden

---

## Sicherheit

* **Kein Zugang von außen**: Schüler-Browser sprechen nur mit Moodle (Moodle-Login, Sesskey, Rechte). Der Runner akzeptiert nur Anfragen mit dem gemeinsamen Geheimnis.
* **Sandbox**: Docker-Container ohne Netzwerk, Root-Dateisystem schreibgeschützt, nur ein kleines `/tmp` (tmpfs), Speicher-, CPU-, Prozess- und Dateigrößenlimits, keine Capabilities außer den drei, die der Harness zum Rechteabgeben braucht, `no-new-privileges`.
* **Schülercode läuft als eigener unprivilegierter Benutzer** (UID 1000) in einem Kindprozess. Der Harness (der die Ergebnisse meldet) läuft unter einer anderen UID und kann vom Schülercode weder beendet noch ausgelesen werden.
* **Grader getrennt**: Die Sandbox liefert nur Rohdaten (Ausgabe, Rückgabewerte, Diagrammstruktur). Verglichen und benotet wird im Runner. Musterlösung und Prüfskripte laufen in eigenen Sandboxes, ihr Code und die erwarteten Werte gelangen nie in die Sandbox der Schüler/innen und nie in deren Browser (bei versteckten / „nur Name“-Tests auch nicht die Eingaben).
* **Getestet** gegen `rm -rf /`, Fork-Bomben, Endlosschleifen, Speicherfresser, Ausgabeflut, Hintergrundprozesse, Netzwerkzugriff und Versuche, den Harness zu beenden.
* Für Testläufe werden Worker-Container wiederverwendet (sonst begrenzt der Docker-Daemon den Durchsatz). Zwischen zwei Jobs werden alle Schülerprozesse beendet sowie `/tmp` und `/dev/shm` geleert. Interaktive Konsolen bekommen immer einen frischen Container.

Leistung (gemessen in der Dev-Umgebung): eine Prüfung 0,05–0,3 s, **30 gleichzeitige Prüfungen ≈ 1 s**.

## Runner-Konfiguration (`runner/.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `MPL_RUNNER_SECRET` | – | Pflicht, ≥ 16 Zeichen |
| `MPL_WORKERS` | CPU-Kerne (4–8) | Worker-Container für Testläufe (= parallele Bewertungen) |
| `MPL_POOL_SIZE` | 4 | vorgestartete Container für die Konsole |
| `MPL_MAX_SESSIONS` | 40 | gleichzeitige Konsolen |
| `MPL_TIMEOUT` / `MPL_MAX_TIMEOUT` | 30 / 120 | Zeitlimit pro Test (Standard / Obergrenze) in s |
| `MPL_MEMORY_MB` / `MPL_MAX_MEMORY_MB` | 512 / 1024 | Speicherlimit (Standard / Obergrenze) |
| `MPL_SESSION_IDLE` / `MPL_SESSION_MAX` | 120 / 600 | Konsole: Inaktivität / maximale Dauer in s |
| `MPL_CPUS` | 1 | CPUs pro Sandbox |
| `MPL_DOCKER_RUNTIME` | – | z. B. `runsc` (gVisor) |

Im Sandbox-Image sind Python 3.12, **matplotlib** und **numpy** enthalten. Weitere Pakete: in `runner/sandbox/Dockerfile` ergänzen und `docker compose build` ausführen.

---

## Entwicklung

```bash
cd dev
./setup.sh            # Moodle 4.4 + Postgres + Runner bauen/starten, installieren, Demo-Kurs anlegen
./setup.sh reset      # alles löschen und neu
# → http://localhost:8080   admin / Admin1234!   lehrer / Test1234!   schueler1 / Test1234!
```

Der Demo-Kurs „Informatik 10 – Python“ enthält fünf Beispielaufgaben (Konsolenprogramm mit Zufallszahlen, Primzahlen mit beliebigem Ausgabeformat, Caesar-Funktion mit versteckten Tests, Klasse Konto, Temperatur-Liniendiagramm).

Tests:

```bash
# Runner (gegen einen laufenden Runner)
MPL_RUNNER_URL=http://localhost:8000 MPL_RUNNER_SECRET=… python3 runner/tests/test_api.py
# Moodle-Webservices (Rechte, Sichtbarkeit, Noten, Konsole)
cd dev && docker compose exec -u www-data moodle php /mpl-dev/test_externals.php
# KI-Hinweise gegen eine Fake-KI (Kosten, Deckel, Rückfragen, was an die KI geht)
MPL_AI_API_KEY=mock MPL_AI_BASE_URL=http://aimock:9000/v1 docker compose --profile aimock up -d
docker compose exec -u www-data moodle php /mpl-dev/test_ai.php
# Browser-E2E (headless Chromium), Screenshots in dev/e2e/shots/
dev/e2e/run.sh
```

JavaScript: Quellen in `mpl/amd/src/` (ES-Module), Build mit `tooling/` (rollup, inkl. CodeMirror-6-Bundle):

```bash
cd tooling && npm install && npx rollup -c      # → mpl/amd/build/*.min.js
```

## Grenzen / bewusst weggelassen

* Nur Python, eine Datei pro Abgabe (keine Mehrdatei-Projekte)
* Meldungen, die der Runner selbst erzeugt (z. B. „Zeitlimit überschritten“, Vergleichshinweise), sind deutsch
* Keine Gruppenabgaben, keine KI-Testvorschläge (lässt sich später über Moodles KI-Subsystem ergänzen)
* Die interaktive Konsole arbeitet per Polling über Moodle (≈ 0,1–0,3 s Verzögerung), ohne WebSockets – dafür ohne direkte Verbindung zum Runner
