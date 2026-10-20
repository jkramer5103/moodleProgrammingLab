#!/usr/bin/env bash
# Local test environment for Moodle Programming Lab:
# Moodle 4.4 + PostgreSQL + runner (+ sandbox image), demo course and users.
#
#   ./setup.sh          build/start everything, install or upgrade Moodle, seed demo data
#   ./setup.sh reset    delete all data (database, moodledata) and start from scratch
#
# Then open http://localhost:8080  –  admin / Admin1234!, lehrer / Test1234!, schueler1 / Test1234!
set -euo pipefail
cd "$(dirname "$0")"

if [[ "${1:-}" == "reset" ]]; then
    docker compose down -v
fi

if [[ ! -f moodle/version.php ]]; then
    echo ">> Moodle 4.4 herunterladen …"
    git clone --depth 1 -b MOODLE_404_STABLE https://github.com/moodle/moodle.git moodle
fi
cp config.php.dev moodle/config.php

echo ">> Container bauen und starten …"
docker compose up -d --build

echo ">> Warte auf die Datenbank …"
for _ in $(seq 1 60); do
    docker compose exec -T db pg_isready -U moodle >/dev/null 2>&1 && break
    sleep 1
done
docker compose exec -T moodle chown -R www-data:www-data /var/www/moodledata

if docker compose exec -T db psql -U moodle -d moodle -tAc "SELECT 1 FROM information_schema.tables WHERE table_name='mdl_config'" | grep -q 1; then
    echo ">> Moodle aktualisieren (Plugin-Upgrade) …"
    docker compose exec -T -u www-data moodle php admin/cli/upgrade.php --non-interactive
else
    echo ">> Moodle installieren …"
    docker compose exec -T -u www-data moodle php admin/cli/install_database.php --agree-license \
        --adminuser=admin --adminpass='Admin1234!' --adminemail=admin@example.com \
        --fullname='Moodle Programming Lab (Dev)' --shortname=mpldev
fi

echo ">> Demo-Kurs anlegen …"
docker compose exec -T -u www-data moodle php /mpl-dev/seed.php

cat <<'MSG'

Fertig:  http://localhost:8080
  admin     / Admin1234!
  lehrer    / Test1234!   (Lehrkraft im Kurs "Informatik 10 – Python")
  schueler1 / Test1234!   (Schüler/in)
  schueler2 / Test1234!
MSG
