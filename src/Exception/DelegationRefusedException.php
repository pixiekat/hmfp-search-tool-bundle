<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Exception;

/**
 * A delegation request that cannot go ahead, for a reason the person asking
 * should be told.
 *
 * The MESSAGE IS USER-FACING: DelegationManager writes it as a sentence for a
 * flash message, and the controller shows it verbatim. That is the difference
 * between this and a \LogicException from the entity — those mean the code
 * asked for something impossible, and should surface as a bug, not a flash.
 */
final class DelegationRefusedException extends \DomainException {
}
