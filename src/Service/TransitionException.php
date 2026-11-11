<?php

namespace Atwx\Flow\Service;

use Atwx\SilverstripeFrontdeskKit\Table\ActionException;

/**
 * A status change was refused. The message is meant for the user; the kit
 * shows it as an error when it comes from a record action.
 */
class TransitionException extends ActionException
{
}
