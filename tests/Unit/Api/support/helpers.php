<?php

// ApiLog::logRequest() calls the global user() helper — normally defined by the
// consuming Laravel app (gpt-manager), not by danx itself. This standalone
// Orchestra\Testbench suite boots no such app, so provide a no-op stand-in only
// when nothing else already defined it.
if (!function_exists('user')) {
    function user()
    {
        return null;
    }
}
