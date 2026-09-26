#!/bin/sh
# Start des Euro-Office DocumentServers mit dem KI-Plugin fuer die Editoren.
#
# Das KI-Plugin liegt im Image nur im AdminPanel; es wird samt Plugin-SDK
# (js, css) nach sdkjs-plugins kopiert und ueber local-production-linux.json
# automatisch gestartet. EUROOFFICE_AI_PLUGIN=false entfernt es wieder.
set -u

root=/var/www/euro-office/documentserver
plugins="$root/sdkjs-plugins"
build="$root/server/AdminPanel/client/build"

if [ "${EUROOFFICE_AI_PLUGIN:-true}" = "true" ] && [ -f "$build/ai/config.json" ]; then
  for dir in ai js css; do
    [ -e "$plugins/$dir" ] || cp -R "$build/$dir" "$plugins/$dir" || true
  done

  # Die Editoren legen die Schaltflaeche fuer Hintergrund-Plugins nur an, wenn
  # zusaetzlich ein sichtbares Plugin vorhanden ist. Ist das KI-Plugin das
  # einzige, bricht die Plugin-Registrierung ab und es startet nie.
  for app in "$root"/web-apps/apps/*/main/app.js; do
    [ -f "$app" ] || continue
    grep -q 'isBackground||me.addBackgroundPluginsButton' "$app" && continue
    sed -i 's#me.backgroundPlugins.length>0){me.viewPlugins.backgroundBtn.show();#me.backgroundPlugins.length>0){isBackground||me.addBackgroundPluginsButton(_group).appendTo(me.$toolbarPanelPlugins),me.viewPlugins.backgroundBtn.show();#' "$app" || true
  done
else
  rm -rf "$plugins/ai"
fi

JWT_SECRET="$(cat /run/secrets/office_jwt_secret)"
export JWT_SECRET
exec /entrypoint.sh "$@"
