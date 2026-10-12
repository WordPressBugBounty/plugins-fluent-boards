<?php

namespace FluentBoards\App\Hooks\Handlers;

/**
 * Keeps authenticated Boards REST callbacks in the user's language without leaking locale changes.
 */
class RestLocaleHandler
{
    protected $routePrefix;

    protected $localeSwitches = [];

    /**
     * Scope locale changes to the configured Boards REST namespace and version.
     */
    public function __construct($restNamespace, $restVersion)
    {
        $this->routePrefix = '/' . trim($restNamespace, '/') . '/' . trim($restVersion, '/') . '/';
    }

    /**
     * Translate authenticated callbacks and policy errors using the user's locale.
     */
    public function switchToUserLocale($response, $handler, $request)
    {
        $route = $request->get_route();
        if (!is_user_logged_in() || strpos($route, $this->routePrefix) !== 0
            || strpos($route, $this->routePrefix . 'public/') === 0) {
            return $response;
        }

        // Track each request separately so nested REST calls cannot restore an outer switch.
        $requestId = spl_object_hash($request);
        $this->localeSwitches[$requestId][] = switch_to_locale(get_user_locale());

        return $response;
    }

    /**
     * Restore only the locale switch made for the completed request.
     */
    public function restoreLocale($response, $handler, $request)
    {
        $requestId = spl_object_hash($request);
        if (!empty($this->localeSwitches[$requestId])) {
            if (array_pop($this->localeSwitches[$requestId])) {
                restore_previous_locale();
            }
            if (!$this->localeSwitches[$requestId]) {
                unset($this->localeSwitches[$requestId]);
            }
        }

        return $response;
    }
}
