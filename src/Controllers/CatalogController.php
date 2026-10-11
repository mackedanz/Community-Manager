<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Catalog\Erkul;
use Hangar\Community;
use Hangar\Constants;
use Hangar\Db;
use Hangar\FleetFilter;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Text;

final class CatalogController extends Controller
{
    private const PAGE_SIZE = 60;
    /** Nur diese Typen stehen im Katalog; alles andere gibt es nur im Hangar. */
    private const CATALOG_KINDS = ['SHIP', 'ARMOR'];

    private const LABELS = [
        'career' => 'Karriere', 'role' => 'Rolle', 'type' => 'Typ', 'subType' => 'Untertyp', 'status' => 'Status',
        'sizeLabel' => 'Größenklasse', 'size' => 'Größe', 'length' => 'Länge (m)', 'beam' => 'Breite (m)',
        'height' => 'Höhe (m)', 'mass' => 'Masse (kg)', 'scmSpeed' => 'SCM-Geschwindigkeit (m/s)',
        'crewMin' => 'Crew min.', 'crewMax' => 'Crew max.', 'cargo' => 'Fracht (SCU)', 'msrp' => 'Pledge-Preis (USD)',
    ];

    public static function index(Request $req): Response
    {
        Auth::requireViewer($req);
        $q = trim((string) $req->query('q', ''));
        $kind = in_array($req->query('kind'), self::CATALOG_KINDS, true) ? (string) $req->query('kind') : 'SHIP';
        $page = min(1000, max(1, (int) $req->query('page', '1')));
        $key = Text::normalizeName($q);
        $sort = FleetFilter::parseSort($req->query);
        $order = $sort === 'name-desc' ? 'name DESC' : 'name ASC';

        $where = 'kind = ?';
        $params = [$kind];
        if ($key !== '') {
            $like = '%' . addcslashes($key, '%_\\') . '%';
            $where .= ' AND (match_key LIKE ? OR alt_match_key LIKE ?)';
            array_push($params, $like, $like);
        }
        $filter = $kind === 'SHIP' ? FleetFilter::parseFilter($req->query) : [];
        $specFilter = array_diff_key($filter, ['q' => 1]);
        $options = [];
        if ($kind === 'SHIP') {
            $options = FleetFilter::options(array_map(
                fn ($r) => FleetFilter::parseSpecs($r['data']),
                Db::all('SELECT data FROM catalog_items WHERE kind = ?', ['SHIP']),
            ));
        }
        $cols = 'id, kind, slug, name, manufacturer, image_url, data';
        if ($specFilter !== []) {
            // Spezifikationen stehen als JSON in der Spalte data; bei aktivem Filter wird in PHP gefiltert und geteilt.
            $matching = array_values(array_filter(
                Db::all("SELECT $cols FROM catalog_items WHERE $where ORDER BY $order", $params),
                fn ($r) => FleetFilter::matches(FleetFilter::parseSpecs($r['data']), $specFilter),
            ));
            $total = count($matching);
            $items = array_slice($matching, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        } else {
            $total = (int) Db::val("SELECT COUNT(*) FROM catalog_items WHERE $where", $params);
            $items = Db::all(
                "SELECT $cols FROM catalog_items WHERE $where ORDER BY $order LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
                $params,
            );
        }
        foreach ($items as &$i) {
            $i['imageSrc'] = Community::imageSrc($i['kind'], $i['slug'], $i['image_url']);
            $specs = FleetFilter::parseSpecs($i['data']);
            $i['meta'] = implode(' · ', array_filter([$specs['sizeLabel'], $specs['career']]));
            $i['notReady'] = $i['kind'] === 'SHIP' ? FleetFilter::notReadyLabel($specs['status']) : null;
            unset($i['data']);
        }
        unset($i);

        return self::page('catalog', [
            'items' => $items, 'q' => $q, 'kind' => $kind, 'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)), 'total' => $total,
            'kinds' => self::CATALOG_KINDS, 'filter' => $specFilter, 'options' => $options, 'sort' => $sort,
            'wide' => true,
        ], 'Katalog');
    }

    public static function show(Request $req, array $p): Response
    {
        $viewer = Auth::requireViewer($req);
        $kind = strtoupper((string) $p['kind']);
        if (!Constants::isKind($kind)) {
            throw HttpException::notFound();
        }
        $item = Db::one('SELECT * FROM catalog_items WHERE kind = ? AND slug = ?', [$kind, (string) $p['slug']]);
        if ($item === null) {
            throw HttpException::notFound();
        }
        $data = json_decode((string) $item['data'], true);
        $data = is_array($data) ? $data : [];
        $rows = [];
        foreach (self::LABELS as $key => $label) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $rows[$label] = (string) $data[$key];
            }
        }
        $modules = Db::all('SELECT name FROM ship_modules WHERE ship_id = ? ORDER BY sort ASC, name ASC', [$item['id']]);

        return self::page('catalog_item', [
            'item' => $item,
            'kind' => $kind,
            'imageSrc' => Community::imageSrc($kind, $item['slug'], $item['image_url']),
            'rows' => $rows,
            'webUrl' => is_string($data['webUrl'] ?? null) ? $data['webUrl'] : null,
            'erkulUrl' => $kind === 'SHIP' ? Erkul::url((string) $item['name'], $item['manufacturer'] ?? null) : null,
            'modules' => array_column($modules, 'name'),
            'owners' => Community::getOwners($item['id'], $viewer),
        ], $item['name']);
    }
}
