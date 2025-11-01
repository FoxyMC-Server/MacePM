<?php

/*MIT License

Copyright (c) 2025 Jasson44

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.*/

declare(strict_types=1);

namespace XeonCh\Mace;

use pocketmine\block\Air;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\StringToItemParser;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\world\particle\BlockBreakParticle;
use XeonCh\Mace\item\Mace;

class EventListener implements Listener
{
    private const KNOCKBACK_RANGE = 3.5;
    private const KNOCKBACK_POWER = 0.7;
    private const FALL_DISTANCE_THRESHOLD = 1.5;
    private const HEAVY_SMASH_THRESHOLD = 5.0;

    private array $playerFallDistance = [];

    public function onPlayerMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();

        $currentY = $event->getTo()->getY();
        $previousY = $event->getFrom()->getY();

        if ($currentY < $previousY) {
            if (!isset($this->playerFallDistance[$player->getName()])) {
                $this->playerFallDistance[$player->getName()] = 0;
            }
            $fallDistance = $this->playerFallDistance[$player->getName()] + ($previousY - $currentY);
            $this->playerFallDistance[$player->getName()] = $fallDistance;
        }
        if ($player->isOnGround()) {
            if (isset($this->playerFallDistance[$player->getName()])) {
                unset($this->playerFallDistance[$player->getName()]);
            }
        }
    }

    public function MaceLogic(EntityDamageByEntityEvent $event): void
    {
        $damager = $event->getDamager();

        if (!$damager instanceof Player) {
            return;
        }

        $player = $damager;
        $item = $player->getInventory()->getItemInHand();

        if (!$item instanceof Mace) {
            return;
        }

        $playerName = $player->getName();
        if (!isset($this->playerFallDistance[$playerName])) {
            return;
        }

        $fallDistance = $this->playerFallDistance[$playerName];
        unset($this->playerFallDistance[$playerName]);

        if ($fallDistance <= self::FALL_DISTANCE_THRESHOLD) {
            return;
        }

        $bonusDamage = $this->calculateBonusDamage($fallDistance);

        $densityEnchant = $item->getEnchantment(StringToEnchantmentParser::getInstance()->parse("density"));
        if ($densityEnchant !== null) {
            $densityLevel = $densityEnchant->getLevel();
            $densityMultiplier = match ($densityLevel) {
                1 => 0.5,
                2 => 1.0,
                3 => 1.5,
                4 => 2.0,
                5 => 2.5,
                default => 0,
            };

            $densityBonus = $fallDistance * $densityMultiplier;
            $bonusDamage += $densityBonus;
        }

        $newDamage = $event->getBaseDamage() + $bonusDamage;
        $event->setBaseDamage($newDamage);

        $target = $event->getEntity();
        if ($target instanceof Player) {
            $enchant = $item->getEnchantment(StringToEnchantmentParser::getInstance()->parse("breach"));
            if ($enchant !== null) {
                $breachLevel = $enchant->getLevel();

                $armorInventory = $target->getArmorInventory();
                $armorPoints = 0;

                foreach ($armorInventory->getContents() as $armorItem) {
                    if (!$armorItem->isNull()) {
                        $armorPoints += $armorItem->getDefensePoints();
                    }
                }

                if ($armorPoints > 0) {
                    $reductionPercent = $armorPoints * 4;
                    $effectiveReduction = max(0, $reductionPercent - (15 * $breachLevel));

                    $finalDamageMultiplier = (100 - $effectiveReduction) / 100;
                    $newDamage = $event->getBaseDamage() * $finalDamageMultiplier;
                    $event->setBaseDamage($newDamage);
                }
            }
        }

        $targetPos = $event->getEntity()->getPosition();
        $world = $player->getWorld();

        $isOnGround = $event->getEntity()->isOnGround();
        $soundName = $isOnGround
            ? ($fallDistance > self::HEAVY_SMASH_THRESHOLD ? "mace.heavy_smash_ground" : "mace.smash_ground")
            : "mace.smash_air";

        $this->playSoundToNearbyPlayers($world, $targetPos, $soundName);

        if ($isOnGround) {
            $this->spawnSmashParticles($world, $targetPos);
        }

        $this->knockbackNearbyEntities($world, $player, $event->getEntity(), $fallDistance);

        $knockback = new Vector3(
            $player->getMotion()->x / 2.0,
            0.01
            $player->getMotion()->z / 2.0
        );

        $windBurstEnchant = $item->getEnchantment(StringToEnchantmentParser::getInstance()->parse("wind_burst"));
        if ($windBurstEnchant !== null) {
            switch ($windBurstEnchant->getLevel()) {
                case 1:
                    $knockback->y = 1.2;
                    break;
                case 2:
                    $knockback->y = 2.0;
                    break;
                case 3:
                    $knockback->y = 3.1;
                    break;
            }
        }

        $player->setMotion($knockback);
    }

    private function calculateBonusDamage(float $fallDistance): float
    {

        if ($fallDistance <= 3.0) {
            return 4.0 * $fallDistance;
        } elseif ($fallDistance <= 8.0) {
            return 12.0 + 2.0 * ($fallDistance - 3.0);
        } else {
            return 22.0 + $fallDistance - 8.0;
        }
    }

    private function knockbackNearbyEntities(\pocketmine\world\World $world, Player $player, \pocketmine\entity\Entity $attacked, float $fallDistance): void
    {
        $targetPos = $attacked->getPosition();
        $range = self::KNOCKBACK_RANGE;

        $boundingBox = new AxisAlignedBB(
            $targetPos->x - $range,
            $targetPos->y - $range,
            $targetPos->z - $range,
            $targetPos->x + $range,
            $targetPos->y + $range,
            $targetPos->z + $range
        );

        $nearbyEntities = $world->getNearbyEntities($boundingBox);

        foreach ($nearbyEntities as $entity) {
            if ($entity === $player || $entity === $attacked) {
                continue;
            }

            if (!($entity instanceof \pocketmine\entity\Living)) {
                continue;
            }

            $distance = $entity->getPosition()->distance($targetPos);
            if ($distance > $range) {
                continue;
            }

            $knockbackStrength = ($range - $distance) * self::KNOCKBACK_POWER;
            if ($fallDistance > self::HEAVY_SMASH_THRESHOLD) {
                $knockbackStrength *= 2;
            }

            $direction = $entity->getPosition()->subtract(
                $targetPos->x,
                $targetPos->y,
                $targetPos->z
            )->normalize();

            $entity->setMotion(new Vector3(
                $direction->x * $knockbackStrength,
                0.7,
                $direction->z * $knockbackStrength
            ));
        }
    }

    private function spawnSmashParticles(\pocketmine\world\World $world, Vector3 $pos): void
    {
        $blockUnder = $world->getBlock($pos->subtract(0, 1, 0));
        $block = ($blockUnder instanceof Air) ? "grass" : $blockUnder->getName();

        $maxHeight = 4.0;
        $step = 0.5;
        $offset = 1.5;

        for ($i = 0; $i <= $maxHeight; $i += $step) {
            $currentY = $pos->y + $i;

            $positions = [
                new Vector3($pos->x + $offset, $currentY, $pos->z),
                new Vector3($pos->x - $offset, $currentY, $pos->z),
                new Vector3($pos->x, $currentY, $pos->z + $offset),
                new Vector3($pos->x, $currentY, $pos->z - $offset),
            ];

            foreach ($positions as $particlePos) {
                $world->addParticle(
                    $particlePos,
                    new BlockBreakParticle(StringToItemParser::getInstance()->parse($block)->getBlock())
                );
            }
        }
    }

    private function playSoundToNearbyPlayers(\pocketmine\world\World $world, Vector3 $pos, string $soundName): void
    {
        $nearbyEntities = $world->getNearbyEntities(new AxisAlignedBB(
            $pos->x - 20,
            $pos->y - 20,
            $pos->z - 20,
            $pos->x + 20,
            $pos->y + 20,
            $pos->z + 20
        ));

        foreach ($nearbyEntities as $entity) {
            if ($entity instanceof Player) {
                $entity->getNetworkSession()->sendDataPacket(PlaySoundPacket::create(
                    $soundName,
                    $pos->x,
                    $pos->y,
                    $pos->z,
                    1.0,
                    1.0
                ));
            }
        }
    }
}
