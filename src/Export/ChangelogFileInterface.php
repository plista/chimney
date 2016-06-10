<?php

/*
 * This file is part of Plista Chimney.
 *
 * (c) plista GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plista\Chimney\Export;

/**
 *
 */
interface ChangelogFileInterface
{
    /**
     * @param string $addon
     */
    public function add($addon);
}
