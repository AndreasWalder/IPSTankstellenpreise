<?php

declare(strict_types=1);

/**
 * ============================================================
 * TANKSTELLENPREISE AT — E-Control / Spritpreisrechner
 * IP-Symcon Modul für günstigste Tankstelle + Top-5
 * ============================================================
 *
 * Änderungsverlauf (Changelog)
 * 2026-03-13: v1.0 — Initiale Version mit Config GUI, Timer,
 *             Variablen, Kartenlink und Top-5 Anzeige.
 */
class TankstellenpreiseAT extends IPSModule
{
    private const API_URL = 'https://api.e-control.at/sprit/1.0/search/gas-stations/by-address';

    public function Create(): void
    {
        parent::Create();

        $this->EnsureVariableProfiles();

        $this->RegisterPropertyFloat('Latitude', 46.8276);
        $this->RegisterPropertyFloat('Longitude', 12.7695);
        $this->RegisterPropertyInteger('RadiusKm', 25);
        $this->RegisterPropertyString('FuelType', 'DIE');
        $this->RegisterPropertyBoolean('IncludeClosed', false);
        $this->RegisterPropertyInteger('UpdateIntervalMinutes', 15);
        $this->RegisterPropertyString('MapsProvider', 'google');

        $this->RegisterTimer('UpdateTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "InternalUpdate", true);');

        $this->RegisterVariableString('CheapestStationName', 'Günstigste Tankstelle', '', 10);
        $this->RegisterVariableFloat('CheapestStationPrice', 'Preis (€/L)', 'TSPAT.Euro2', 20);
        $this->RegisterVariableFloat('CheapestStationDistance', 'Entfernung (km)', 'TSPAT.DistanceKm', 30);
        $this->RegisterVariableString('CheapestStationAddress', 'Adresse', '', 40);
        $this->RegisterVariableString('CheapestStationLastUpdated', 'Preis zuletzt gemeldet', '', 50);
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung', '~String', 60);
        $this->RegisterVariableString('MapsLink', 'Kartenlink', '~HTMLBox', 70);
        $this->RegisterVariableString('Top5Html', 'Top-5 Anzeige', '~HTMLBox', 80);
        $this->RegisterVariableString('RawJson', 'Raw JSON', '~String', 90);
    }

    private function EnsureVariableProfiles(): void
    {
        if (!IPS_VariableProfileExists('TSPAT.Euro2')) {
            IPS_CreateVariableProfile('TSPAT.Euro2', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('TSPAT.Euro2', '', ' €');
        IPS_SetVariableProfileDigits('TSPAT.Euro2', 3);

        if (!IPS_VariableProfileExists('TSPAT.DistanceKm')) {
            IPS_CreateVariableProfile('TSPAT.DistanceKm', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('TSPAT.DistanceKm', '', ' km');
        IPS_SetVariableProfileDigits('TSPAT.DistanceKm', 2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $intervalMinutes = max(1, $this->ReadPropertyInteger('UpdateIntervalMinutes'));
        $this->SetTimerInterval('UpdateTimer', $intervalMinutes * 60 * 1000);
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => 'Offizielle Tankstellendaten Österreich (E-Control / Spritpreisrechner)'
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Standort / Suchbereich',
                    'items' => [
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'Latitude',
                            'caption' => 'Breitengrad',
                            'digits' => 5
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'Longitude',
                            'caption' => 'Längengrad',
                            'digits' => 5
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'RadiusKm',
                            'caption' => 'Suchradius (km)'
                        ]
                    ]
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Abfrage',
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'FuelType',
                            'caption' => 'Kraftstoff',
                            'options' => [
                                ['caption' => 'Diesel', 'value' => 'DIE'],
                                ['caption' => 'Super', 'value' => 'SUP'],
                                ['caption' => 'Super Plus', 'value' => 'SUPPLUS']
                            ]
                        ],
                        [
                            'type' => 'CheckBox',
                            'name' => 'IncludeClosed',
                            'caption' => 'Geschlossene Tankstellen mit einbeziehen'
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'UpdateIntervalMinutes',
                            'caption' => 'Aktualisierung alle X Minuten'
                        ],
                        [
                            'type' => 'Select',
                            'name' => 'MapsProvider',
                            'caption' => 'Kartenlink',
                            'options' => [
                                ['caption' => 'Google Maps', 'value' => 'google'],
                                ['caption' => 'OpenStreetMap', 'value' => 'osm']
                            ]
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Jetzt aktualisieren',
                    'onClick' => 'IPS_RequestAction($id, "InternalUpdate", true);'
                ]
            ],
            'status' => [
                ['code' => 101, 'icon' => 'inactive', 'caption' => 'Instanz wird erstellt'],
                ['code' => 102, 'icon' => 'active', 'caption' => 'Modul aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Modul inaktiv'],
                ['code' => 200, 'icon' => 'error', 'caption' => 'Fehler bei der API-Abfrage']
            ]
        ];

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ((string) $Ident) {
            case 'InternalUpdate':
                $this->Update();
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function Update(): void
    {
        $lat = $this->NormalizeCoordinate($this->ReadPropertyFloat('Latitude'));
        $lon = $this->NormalizeCoordinate($this->ReadPropertyFloat('Longitude'));
        $radius = max(1, $this->ReadPropertyInteger('RadiusKm'));
        $fuelType = $this->NormalizeFuelType($this->ReadPropertyString('FuelType'));
        $includeClosed = $this->ReadPropertyBoolean('IncludeClosed') ? 'true' : 'false';

        $query = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'fuelType' => $fuelType,
            'radius' => $radius,
            'includeClosed' => $includeClosed
        ]);

        $url = self::API_URL . '?' . $query;

        try {
            $json = $this->Request($url);
            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                throw new Exception('API-Antwort ist kein gültiges JSON.');
            }

            $stations = $this->ExtractStations($decoded);

            if (count($stations) === 0) {
                $this->SetValue('CheapestStationName', 'Keine Tankstelle gefunden');
                $this->SetValue('CheapestStationPrice', 0);
                $this->SetValue('CheapestStationDistance', 0);
                $this->SetValue('CheapestStationAddress', '');
                $this->SetValue('CheapestStationLastUpdated', '');
                $this->SetValue('MapsLink', $this->BuildInfoHtml('Keine Tankstellen im gewählten Radius gefunden.'));
                $this->SetValue('Top5Html', $this->BuildInfoHtml('Keine Daten vorhanden.'));
                $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
                $this->SetValue('RawJson', $json);
                $this->SendDebug('Update', 'Keine Tankstellen gefunden', 0);
                $this->SetStatus(102);
                return;
            }

            usort($stations, static function (array $a, array $b): int {
                $priceCompare = $a['price'] <=> $b['price'];
                if ($priceCompare !== 0) {
                    return $priceCompare;
                }

                return $a['distance'] <=> $b['distance'];
            });

            $cheapest = $stations[0];

            $this->SetValue('CheapestStationName', (string) $cheapest['name']);
            $this->SetValue('CheapestStationPrice', round((float) $cheapest['price'], 2));
            $this->SetValue('CheapestStationDistance', (float) $cheapest['distance']);
            $this->SetValue('CheapestStationAddress', (string) $cheapest['address']);
            $this->SetValue('CheapestStationLastUpdated', (string) $cheapest['lastUpdated']);
            $this->SetValue('MapsLink', $this->BuildMapsHtml($cheapest));
            $this->SetValue('Top5Html', $this->BuildTop5Html(array_slice($stations, 0, 5), $fuelType));
            $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
            $this->SetValue('RawJson', $json);

            $this->SendDebug('Update', $json, 0);
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->SetStatus(200);
            $this->LogMessage('TankstellenpreiseAT Fehler: ' . $e->getMessage(), KL_ERROR);
            throw $e;
        }
    }

    private function Request(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => "User-Agent: IP-Symcon TankstellenpreiseAT\r\nAccept: application/json\r\n"
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new Exception('API-Abfrage fehlgeschlagen: ' . $url);
        }

        return $result;
    }

    private function ExtractStations(array $decoded): array
    {
        $candidates = [];

        if (isset($decoded['gasStations']) && is_array($decoded['gasStations'])) {
            $candidates = $decoded['gasStations'];
        } elseif (isset($decoded['results']) && is_array($decoded['results'])) {
            $candidates = $decoded['results'];
        } elseif (array_is_list($decoded)) {
            $candidates = $decoded;
        }

        $stations = [];
        foreach ($candidates as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $price = $this->ExtractPrice($entry);
            if ($price === null) {
                continue;
            }

            $stations[] = [
                'name' => (string) ($entry['name'] ?? $entry['company'] ?? 'Unbekannte Tankstelle'),
                'price' => $price,
                'distance' => round((float) ($entry['distance'] ?? 0), 2),
                'address' => $this->BuildAddress($entry),
                'lastUpdated' => $this->ExtractLastUpdated($entry),
                'latitude' => $this->NormalizeCoordinate($this->ExtractLatitude($entry)),
                'longitude' => $this->NormalizeCoordinate($this->ExtractLongitude($entry))
            ];
        }

        return $stations;
    }

    private function ExtractPrice(array $entry): ?float
    {
        if (isset($entry['price']) && is_numeric($entry['price'])) {
            return round((float) $entry['price'], 3);
        }

        if (isset($entry['prices']) && is_array($entry['prices']) && isset($entry['prices'][0]) && is_array($entry['prices'][0])) {
            $first = $entry['prices'][0];
            if (isset($first['amount']) && is_numeric($first['amount'])) {
                return round((float) $first['amount'], 3);
            }
            if (isset($first['price']) && is_numeric($first['price'])) {
                return round((float) $first['price'], 3);
            }
        }

        return null;
    }

    private function ExtractLastUpdated(array $entry): string
    {
        $value = $entry['lastUpdated'] ?? $entry['lastUpdate'] ?? $entry['date'] ?? '';
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }

        return date('d.m.Y H:i:s', $ts);
    }

    private function ExtractLatitude(array $entry): float
    {
        foreach (['latitude', 'lat'] as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        if (isset($entry['location']) && is_array($entry['location']) && isset($entry['location']['latitude']) && is_numeric($entry['location']['latitude'])) {
            return (float) $entry['location']['latitude'];
        }

        return 0.0;
    }

    private function ExtractLongitude(array $entry): float
    {
        foreach (['longitude', 'lng', 'lon'] as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        if (isset($entry['location']) && is_array($entry['location']) && isset($entry['location']['longitude']) && is_numeric($entry['location']['longitude'])) {
            return (float) $entry['location']['longitude'];
        }

        return 0.0;
    }

    private function NormalizeCoordinate(float $value): float
    {
        return round($value, 5);
    }

    private function BuildAddress(array $entry): string
    {
        $parts = [];

        if (isset($entry['address']) && is_array($entry['address'])) {
            $address = $entry['address'];
            $street = trim(((string) ($address['street'] ?? '')) . ' ' . ((string) ($address['houseNumber'] ?? '')));
            $city = trim(((string) ($address['postalCode'] ?? '')) . ' ' . ((string) ($address['city'] ?? '')));

            if ($street !== '') {
                $parts[] = $street;
            }
            if ($city !== '') {
                $parts[] = $city;
            }
        } else {
            $street = trim(((string) ($entry['street'] ?? '')) . ' ' . ((string) ($entry['houseNumber'] ?? '')));
            $city = trim(((string) ($entry['postalCode'] ?? '')) . ' ' . ((string) ($entry['city'] ?? '')));

            if ($street !== '') {
                $parts[] = $street;
            }
            if ($city !== '') {
                $parts[] = $city;
            }
        }

        return implode(', ', $parts);
    }

    private function BuildMapsHtml(array $station): string
    {
        $provider = $this->ReadPropertyString('MapsProvider');
        $lat = (float) ($station['latitude'] ?? 0.0);
        $lon = (float) ($station['longitude'] ?? 0.0);
        $address = rawurlencode((string) ($station['address'] ?? ''));
        $name = htmlspecialchars((string) ($station['name'] ?? 'Tankstelle'), ENT_QUOTES);

        if ($provider === 'osm') {
            $url = ($lat !== 0.0 || $lon !== 0.0)
                ? 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=16/' . $lat . '/' . $lon
                : 'https://www.openstreetmap.org/search?query=' . $address;
        } else {
            $url = ($lat !== 0.0 || $lon !== 0.0)
                ? 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lon
                : 'https://www.google.com/maps/search/?api=1&query=' . $address;
        }

        return '<div style="padding:8px 10px; font-family:Arial;">'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank">Karte für ' . $name . ' öffnen</a>'
            . '</div>';
    }

    private function BuildTop5Html(array $stations, string $fuelType): string
    {
        $fuelLabel = match ($fuelType) {
            'SUP' => 'Super',
            'SUPPLUS' => 'Super Plus',
            default => 'Diesel'
        };

        $html = '<div style="font-family:Arial; padding:8px 10px;">';
        $html .= '<div style="font-size:16px; font-weight:bold; margin-bottom:8px;">Top-5 Tankstellen (' . htmlspecialchars($fuelLabel, ENT_QUOTES) . ')</div>';
        $html .= '<table style="width:100%; border-collapse:collapse;">';
        $html .= '<tr>';
        $html .= '<th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">#</th>';
        $html .= '<th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Name</th>';
        $html .= '<th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Preis</th>';
        $html .= '<th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">km</th>';
        $html .= '<th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Adresse</th>';
        $html .= '</tr>';

        foreach ($stations as $index => $station) {
            $html .= '<tr>';
            $html .= '<td style="padding:4px; border-bottom:1px solid #eee;">' . ($index + 1) . '</td>';
            $html .= '<td style="padding:4px; border-bottom:1px solid #eee;">' . htmlspecialchars((string) $station['name'], ENT_QUOTES) . '</td>';
            $html .= '<td style="padding:4px; border-bottom:1px solid #eee;">' . number_format((float) $station['price'], 3, ',', '.') . ' €/L</td>';
            $html .= '<td style="padding:4px; border-bottom:1px solid #eee;">' . number_format((float) $station['distance'], 2, ',', '.') . '</td>';
            $html .= '<td style="padding:4px; border-bottom:1px solid #eee;">' . htmlspecialchars((string) $station['address'], ENT_QUOTES) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table></div>';
        return $html;
    }

    private function BuildInfoHtml(string $text): string
    {
        return '<div style="font-family:Arial; padding:8px 10px;">' . htmlspecialchars($text, ENT_QUOTES) . '</div>';
    }

    private function NormalizeFuelType(string $fuelType): string
    {
        $fuelType = strtoupper(trim($fuelType));

        return match ($fuelType) {
            'DIE', 'SUP', 'SUPPLUS' => $fuelType,
            default => 'DIE'
        };
    }
}
