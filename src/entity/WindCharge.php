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

namespace XeonCh\Mace\entity;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Button;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Trapdoor;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\Throwable;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\world\sound\FireExtinguishSound;
use XeonCh\Mace\particle\WindParticle;

class WindCharge extends Throwable
{
    public const WIND_CHARGE_PROJECTILE = "minecraft:wind_charge_projectile";

    public static function getNetworkTypeId(): string
    {
        return self::WIND_CHARGE_PROJECTILE;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(0.3125, 0.3125);
    }

    protected function getName(): string
    {
        return "Wind Charge Projectile";
    }

    protected function getInitialDragMultiplier(): float
    {
        return 1.0;
    }

    protected function getInitialGravity(): float
    {
        return 0.0;
    }

    public function entityBaseTick(int $tickDiff = 1): bool
    {
        $hasUpdate = parent::entityBaseTick($tickDiff);

        if ($this->isOnFire()) {
            $this->extinguish();
            $this->getWorld()->addSound($this->location, new FireExtinguishSound());
        }

        $world = $this->getWorld();
        $block = $world->getBlock($this->location);
        
        if ($block->getTypeId() === BlockTypeIds::WATER || 
            $block->getTypeId() === BlockTypeIds::LAVA) {
            $this->motion = $this->motion->multiply(0.65);
        }

        if ($this->ticksLived % 5 === 0) {
            $this->motion = $this->motion->multiply(0.98);
        }

        return $hasUpdate;
    }

    private function getBurstRadius(): float
    {
        return 2.0;
    }

    private function getKnockbackStrength(): float
    {
        return 0.2;
    }

    protected function onHit(ProjectileHitEvent $event): void
    {
        $world = $this->getWorld();
        
        $world->addParticle($this->location, new WindParticle());
        
        $this->playWindBurstSound();
        
        $blockHit = $event->getBlockHit();
        if ($blockHit !== null) {
            $block = $blockHit->getBlock();
            $this->interactWithBlock($block);
        }
        
        $radius = $this->getBurstRadius();
        $boundingBox = new AxisAlignedBB(
            $this->location->x - $radius,
            $this->location->y - $radius,
            $this->location->z - $radius,
            $this->location->x + $radius,
            $this->location->y + $radius,
            $this->location->z + $radius
        );
        
        $nearbyEntities = $world->getNearbyEntities($boundingBox);
        foreach ($nearbyEntities as $entity) {
            if ($entity === null || $entity === $this) {
                continue;
            }
            
            if ($entity instanceof Living) {
                $owner = $this->getOwningEntity();
                if ($owner === null || $entity->getId() !== $owner->getId()) {
                    $entity->attack(new EntityDamageEvent(
                        $entity, 
                        EntityDamageEvent::CAUSE_PROJECTILE, 
                        1.0
                    ));
                }
            }
            
            $this->knockBack($entity);
        }
    }

    protected function knockBack(Entity $entity): void
    {
        $direction = $entity->getPosition()->subtract(
            $this->getPosition()->x,
            $this->getPosition()->y,
            $this->getPosition()->z
        )->normalize();
        
        $knockbackStrength = $this->getKnockbackStrength();
        
        $entity->setMotion(new Vector3(
            $direction->x * $knockbackStrength,
            0.4,
            $direction->z * $knockbackStrength
        ));
    }

    private function interactWithBlock(Block $block): void
    {
        $world = $this->getWorld();
        $pos = $block->getPosition();
        
        $owner = $this->getOwningEntity();
        $player = $owner instanceof Player ? $owner : null;

        if ($block instanceof Door || $block instanceof Trapdoor || $block instanceof FenceGate) {
            $block->onInteract($player);
        }

        if ($block instanceof Button) {
            $block->onInteract($player);
        }

        if ($block->getTypeId() === BlockTypeIds::LEVER) {
            $block->onInteract($player);
        }

        if ($block->getTypeId() === BlockTypeIds::CANDLE || 
            $block->getTypeId() === BlockTypeIds::CANDLE_CAKE) {
            if ($block->getLightLevel() > 0) {
                $world->setBlock($pos, $block->setLit(false));
            }
        }

        if ($block->getTypeId() === BlockTypeIds::CAMPFIRE) {
            if (method_exists($block, 'isLit') && $block->isLit()) {
                $world->setBlock($pos, $block->setLit(false));
            }
        }
    }

    private function playWindBurstSound(): void
    {
        $x = $this->getPosition()->getX();
        $y = $this->getPosition()->getY();
        $z = $this->getPosition()->getZ();

        $aabb = new AxisAlignedBB($x - 15, $y - 15, $z - 15, $x + 15, $y + 15, $z + 15);
        $nearbyPlayers = $this->getWorld()->getNearbyEntities($aabb);
        
        foreach ($nearbyPlayers as $entity) {
            if ($entity instanceof Player) {
                $entity->getNetworkSession()->sendDataPacket(
                    PlaySoundPacket::create("wind_charge.burst", $x, $y, $z, 1.0, 1.0)
                );
            }
        }
    }

    public function attack(EntityDamageEvent $source): void
    {
        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager !== null) {
                $this->setMotion($damager->getDirectionVector()->multiply(-1)->normalize()->multiply(0.5));
            }
        }
        parent::attack($source);
    }
}
