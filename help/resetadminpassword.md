sed -n 's/^ADMIN_PASSWORD=//p' .env | tail -n1 | sed -e 's/^"//;s/"$//' -e "s/^'//;s/'\$//" \
  | docker compose exec -T app sh -c 'ADMIN_PASSWORD="$(cat)" php scripts/create_admin.php admin'
