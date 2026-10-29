<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use RuntimeException;

/** An invitation can't be made or accepted; the message is fit to show the person. */
class InvitationRefused extends RuntimeException {}
