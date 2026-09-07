<?php

namespace ULCAR\Datastar\enums;

defined( 'ABSPATH' ) || exit;

enum EventType: string
{
    // An event for patching HTML elements into the DOM.
    case PatchElements = 'datastar-patch-elements';

    // An event for patching signals.
    case PatchSignals = 'datastar-patch-signals';
}
