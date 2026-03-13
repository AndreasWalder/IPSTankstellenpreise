# TankstellenpreiseAT

IP-Symcon Modul für Österreich / Osttirol mit offizieller E-Control Spritpreisrechner API.

## Funktionen
- Config GUI
- Timer für automatische Aktualisierung
- Variablen für günstigste Tankstelle
- Kartenlink (Google Maps oder OpenStreetMap)
- Top-5 Anzeige als HTML

## Standardwerte
- Standort: Lienz / Osttirol
- Radius: 25 km
- Kraftstoff: Diesel
- Aktualisierung: 15 Minuten

## Installation
1. Ordner `TankstellenpreiseAT` in dein IP-Symcon `modules` Verzeichnis kopieren.
2. In IP-Symcon Module neu laden.
3. Instanz `TankstellenpreiseAT` anlegen.
4. Koordinaten / Radius / Kraftstoff einstellen.
5. Auf `Jetzt aktualisieren` klicken.

## Hinweise
- Datenquelle: offizielle E-Control Spritpreisrechner API.
- Das Modul ist defensiv aufgebaut, falls sich das JSON leicht ändert.
