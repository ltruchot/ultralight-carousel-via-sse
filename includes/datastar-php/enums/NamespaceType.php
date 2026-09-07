<?php

namespace ULCAR\Datastar\enums;

defined( 'ABSPATH' ) || exit;

enum NamespaceType: string
{
    case Html = 'html';
    case Svg = 'svg';
    case MathML = 'mathml';
}
