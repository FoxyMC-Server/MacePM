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

use pocketmine\block\Water;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\Throwable;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Trapdoor;
use pocketmine\block\Button;
use pocketmine\block\Lever;
use pocketmine\block\Bell;
use pocketmine\block\Candle;
use pocketmine\block\Fire;
use pocketmine\block\Lava;
use pocketmine\block\Block;
use pocketmine\world\sound\DoorSound;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\RedstonePowerOffSound;
use pocketmine\world\sound\RedstonePowerOnSound;
use XeonCh\Mace\particle\WindParticle;

class WindCharge extends Throwable
{
    public const WIND_CHARGE_PROJECTILE = "minecraft:wind_charge_projectile";

    /** @var bool */
    protected bool $isOnFire = false;

    public static function getNetworkTypeId(): string
    {
        return self::WIND_CHARGE_PROJECTILE;
    }

    protected function getInitialSizeInfo() : EntitySizeInfo
    {
        return new EntitySizeInfo(0.3125, 0.3125);
    }
    protected function getName(): string
    {
        return "Wind Charge Projectile";
    }

    private function getBurstRadius() : float
    {
        return 2.0;
    }

    private function  getKnockbackStrength() : float
    {
        return 0.2;
    }

    public function entityBaseTick(int $tickDiff = 1): bool
    {
        $hasUpdate = parent::entityBaseTick($tickDiff);

        if (!$this->isFlaggedForDespawn() && $this->ticksLived % 5 === 0) {
            $motion = $this->getMotion();
            $spread = 0.05;
            $this->setMotion(new Vector3(
                $motion->x + (mt_rand(-10, 10) / 100) * $spread,
                $motion->y + (mt_rand(-10, 10) / 100) * $spread,
                $motion->z + (mt_rand(-10, 10) / 100) * $spread
            ));
        }

        $world = $this->getWorld();
        $block = $world->getBlock($this->location);
        if ($block instanceof Fire || $block instanceof Lava) {
            $this->isOnFire = true;
        }

        return $hasUpdate;
    }


    protected function onHit(ProjectileHitEvent $event): void
    {
        $world = $this->getWorld();
        $world->addParticle($this->location, new WindParticle());
        $radius = $this->getBurstRadius();
        $boundingBox = new AxisAlignedBB(
            $this->location->x - $radius,
            $this->location->y - $radius,
            $this->location->z - $radius,
            $this->location->x + $radius,
            $this->location->y + $radius,
            $this->location->z + $radius
        );
        $this->playWindBurstSound();
        $this->processWindBurstEffect();
        $nearbyEntities = $world->getNearbyEntities($boundingBox);
        foreach ($nearbyEntities as $entity) {
            if ($entity !== null) {
                if ($entity instanceof $this) {
                    return;
                }
                if ($entity instanceof Living) {
                    if ($entity->getId() !== $this->getOwningEntity()?->getId()) {
                        $entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_PROJECTILE, 1));
                    }
                }
                $this->knockBack($entity);
            }
        }
    }

    private function processBlockInteraction(Block $block, Vector3 $position): void
    {
        $world = $this->getWorld();
        if ($block instanceof Door && !str_contains(strtolower($block->getName()), "iron")) {
            $block->setOpen(!$block->isOpen());
            $other = $block->getSide($block->isTop() ? Facing::DOWN : Facing::UP);
            if($other instanceof Door && $other->hasSameTypeId($block)){
                $other->setOpen($block->isOpen());
                $world->setBlock($other->getPosition(), $other);
		    }
            $world->setBlock($position, $block);
            $world->addSound($block->getPosition(), new DoorSound());
        }

        if ($block instanceof Trapdoor && !str_contains(strtolower($block->getName()), "iron")) {
            $block->setOpen(!$block->isOpen());
            $world->setBlock($position, $block);
            $world->addSound($block->getPosition(), new DoorSound());
        }

        if ($block instanceof FenceGate) {
            $block->setOpen(!$block->isOpen());
            if($block->isOpen()){
                $maceFacing = $this->getHorizontalFacing();
                if($maceFacing === Facing::opposite($block->getFacing())){
                    $block->setFacing($maceFacing);
                }
            }

            $world = $block->getPosition()->getWorld();
            $world->setBlock($block->getPosition(), $block);
            $world->addSound($this->getPosition(), new DoorSound());
        }

        if ($block instanceof Button) {
            if(!$block->isPressed()){
		    	$block->setPressed(true);
		    	$world = $block->getPosition()->getWorld();
		    	$world->setBlock($block->getPosition(), $block);
			    $world->scheduleDelayedBlockUpdate($block->getPosition(), 1);
		    	$world->addSound($this->getPosition()->add(0.5, 0.5, 0.5), new RedstonePowerOnSound());
		    }
        }

        if ($block instanceof Lever) {
            $block->setActivated(!$block->isActivated());
            $world->setBlock($position, $block);
            $world->addSound(
			    $this->getPosition()->add(0.5, 0.5, 0.5),
			    $block->isActivated() ? new RedstonePowerOnSound() : new RedstonePowerOffSound()
		    );
        }

        if ($block instanceof Bell) {
            $faceHit = Facing::opposite($this->getHorizontalFacing());

            $block->ring($faceHit);
        }

        if ($block instanceof Candle && $block->isLit()) {
            $block->setLit(false);
            $world->addSound($block->getPosition(), new FireExtinguishSound());
            $world->setBlock($position, $block);
        }

    }

    protected function knockBack(Entity $entity) : void
    {
        $knockback = new Vector3($entity->getMotion()->x, $entity->getMotion()->y, $entity->getMotion()->z);

        $knockback->x /= 2.0;
        $knockback->y /= 2.0;
        $knockback->z /= 2.0;

        $knockback->x -= ($this->getPosition()->x - $entity->getPosition()->x) * $this->getKnockbackStrength();
        $knockback->y += 1.0;
        $knockback->z -= ($this->getPosition()->z - $entity->getPosition()->z) * $this->getKnockbackStrength();
        if ($this->isOnFire){
            $entity->setOnFire(5);
        }
        $entity->setMotion($knockback);
    }

    private function processWindBurstEffect(): void
    {
        $radius = $this->getBurstRadius();
        $world = $this->getWorld();
        $boundingBox = new AxisAlignedBB(
            $this->location->x - $radius,
            $this->location->y - $radius,
            $this->location->z - $radius,
            $this->location->x + $radius,
            $this->location->y + $radius,
            $this->location->z + $radius
        );

        for ($x = (int)floor($boundingBox->minX); $x <= (int)floor($boundingBox->maxX); $x++) {
            for ($y = (int)floor($boundingBox->minY); $y <= (int)floor($boundingBox->maxY); $y++) {
                for ($z = (int)floor($boundingBox->minZ); $z <= (int)floor($boundingBox->maxZ); $z++) {
                    $pos = new Vector3($x, $y, $z);
                    $distance = $pos->distance($this->location);
                    if ($distance <= $radius) {
                        $block = $world->getBlock($pos);
                        $this->processBlockInteraction($block, $pos);
                    }
                }
            }
        }
    }

    private function playWindBurstSound(): void
    {
        $x = $this->getPosition()->getX();
        $y = $this->getPosition()->getY();
        $z = $this->getPosition()->getZ();

        $aabb = new AxisAlignedBB($x - 15, $y - 15, $z - 15, $x + 15, $y + 15, $z + 15);
        $nearbyP = $this->getWorld()->getNearbyEntities($aabb);
        foreach ($nearbyP as $near) {
            if ($near instanceof Player) {
                $near->getNetworkSession()->sendDataPacket(PlaySoundPacket::create("wind_charge.burst", $x, $y, $z, 1.0, 1.0));
            }
        }
    }

    public function attack(EntityDamageEvent $source): void
    {
        parent::attack($source);

        if ($source->getCause() === EntityDamageEvent::CAUSE_PROJECTILE ||
            $source->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK) {

            $motion = $this->getMotion();
            $this->setMotion($motion->multiply(-1));

            if ($source instanceof EntityDamageByEntityEvent) {
                $this->setOwningEntity($source->getDamager());
            }
        }
    }


    public function move(float $dx, float $dy, float $dz): void
    {
        $block = $this->getWorld()->getBlock($this->location);

        if ($block instanceof Water || $block instanceof Lava) {
            $motion = $this->getMotion();
            $this->setMotion($motion->multiply(0.5));
        }

        parent::move($dx, $dy, $dz);
    }
}
