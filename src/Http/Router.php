<?php

declare(strict_types=1);

namespace Hangar\Http;

use Hangar\Auth;

/**
 * Minimaler Router: Muster mit {name}-Platzhaltern (ein Pfadsegment), Handler bekommen
 * (Request, Parameter) und liefern eine Response. POST-Routen prüfen das CSRF-Token, außer sie
 * sind als API-Route markiert (dann gilt Bearer-Token bzw. JSON-Pflicht).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,csrf:bool}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler, false);
    }

    public function post(string $pattern, callable $handler, bool $csrf = true): void
    {
        $this->add('POST', $pattern, $handler, $csrf);
    }

    private function add(string $method, string $pattern, callable $handler, bool $csrf): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#i', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'csrf' => $csrf];
    }

    public function dispatch(Request $req): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $req->path, $m)) {
                continue;
            }
            $pathMatched = true;
            $method = $req->method === 'HEAD' ? 'GET' : $req->method;
            if ($r['method'] !== $method) {
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            try {
                if ($r['method'] === 'POST' && $r['csrf'] && !Auth::csrfValid($req)) {
                    return Response::text('Ungültiges oder fehlendes Sicherheitstoken. Seite neu laden und erneut versuchen.', 403);
                }
                return ($r['handler'])($req, $params);
            } catch (HttpException $e) {
                return $e->response;
            }
        }
        return $pathMatched ? Response::text('Methode nicht erlaubt', 405) : Response::notFound();
    }
}
