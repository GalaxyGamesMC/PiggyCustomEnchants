<?php

namespace PiggyCustomEnchants;

use PiggyCustomEnchants\CustomEnchants\CustomEnchants;
use PiggyCustomEnchants\Entities\Fireball;
use PiggyCustomEnchants\Entities\PigProjectile;
use PiggyCustomEnchants\Tasks\GoeyTask;
use PiggyCustomEnchants\Tasks\GrapplingTask;
use pocketmine\block\Block;
use pocketmine\entity\Arrow;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\entity\Projectile;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockEvent;
use pocketmine\event\entity\EntityArmorChangeEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\event\Event;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\Item;
use pocketmine\level\Explosion;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\Player;
use pocketmine\utils\Random;
use pocketmine\utils\TextFormat;

/**
 * Class EventListener
 * @package PiggyCustomEnchants
 */
class EventListener implements Listener
{
    private $plugin;

    /**
     * EventListener constructor.
     * @param Main $plugin
     */
    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @param BlockBreakEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onBreak(BlockBreakEvent $event)
    {
        $player = $event->getPlayer();
        $this->checkToolEnchants($player, $event);
    }

    /**
     * @param EntityArmorChangeEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onArmorChange(EntityArmorChangeEvent $event)
    {
        $entity = $event->getEntity();
        $this->checkArmorEnchants($entity, $event);
    }

    /**
     * @param EntityDamageEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     * @return bool
     */
    public function onDamage(EntityDamageEvent $event)
    {
        $entity = $event->getEntity();
        $cause = $event->getCause();
        $this->checkArmorEnchants($entity, $event);
        if ($cause == EntityDamageEvent::CAUSE_FALL && $entity instanceof Player && isset($this->plugin->nofall[strtolower($entity->getName())])) {
            unset($this->plugin->nofall[strtolower($entity->getName())]);
            $event->setCancelled();
        }
        if ($event instanceof EntityDamageByChildEntityEvent) {
            $damager = $event->getDamager();
            $child = $event->getChild();
            if ($damager instanceof Player && $child instanceof Projectile) {
                $this->checkGlobalEnchants($damager, $entity, $event);
                $this->checkBowEnchants($damager, $entity, $event);
            }
        }
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Player) {
                if ($damager->getInventory()->getItemInHand()->getId() == Item::BOW) { //TODO: Move to canUse() function
                    return false;
                }
                $this->checkGlobalEnchants($damager, $entity, $event);
            }
        }
        return true;
    }

    /**
     * @param EntitySpawnEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onSpawn(EntitySpawnEvent $event)
    {
        $entity = $event->getEntity();
        if ($entity instanceof Projectile && $entity->shootingEntity instanceof Player) {
            if (!isset($entity->namedtag["Volley"])) {
                $this->checkBowEnchants($entity->shootingEntity, $entity, $event);
            }
        }
    }

    /**
     * @param PlayerDeathEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     */
    public function onDeath(PlayerDeathEvent $event)
    {
        $player = $event->getEntity();
        $this->checkGlobalEnchants($player, null, $event);
    }

    /**
     * @param PlayerMoveEvent $event
     *
     * @priority HIGHEST
     * @ignoreCancelled true
     * @return bool
     */
    public function onMove(PlayerMoveEvent $event)
    {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        if (isset($this->plugin->nofall[strtolower($player->getName())])) {
            if ($player->getLevel()->getBlock($player->subtract(0, 1))->getId() !== Block::AIR && $this->plugin->nofall[strtolower($player->getName())] < time()) {
                unset($this->plugin->nofall[strtolower($player->getName())]);
            } else {
                $this->plugin->nofall[strtolower($player->getName())]++;
            }
        }
        if ($from->getFloorX() == $player->getFloorX() && $from->getFloorY() == $player->getFloorY() && $from->getFloorZ() == $player->getFloorZ()) {
            return false;
        }
        $this->checkGlobalEnchants($player, null, $event);
        return true;
    }

    /**
     * @param ProjectileHitEvent $event
     */
    public function onHit(ProjectileHitEvent $event)
    {
        $entity = $event->getEntity();
        if ($entity->shootingEntity instanceof Player) {
            $this->checkBowEnchants($entity->shootingEntity, $entity, $event);
        }
    }

    /**
     * @param Player $damager
     * @param Entity $entity
     * @param EntityEvent|Event $event
     */
    public function checkGlobalEnchants(Player $damager, Entity $entity = null, Event $event)
    {
        //TODO: Check to make sure you can use enchant with item
        if ($event instanceof EntityDamageEvent) {
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::LIFESTEAL);
            if ($enchantment !== null) {
                if ($damager->getHealth() + 2 + $enchantment->getLevel() <= $damager->getMaxHealth()) {
                    $damager->setHealth($damager->getHealth() + 2 + $enchantment->getLevel());
                } else {
                    $damager->setHealth($damager->getMaxHealth());
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::BLIND);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::BLINDNESS);
                $effect->setAmplifier(0);
                $effect->setDuration(100 + 20 * $enchantment->getLevel());
                $effect->setVisible(false);
                $entity->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::DEATHBRINGER);
            if ($enchantment !== null) {
                $damage = 2 + ($enchantment->getLevel() / 10);
                $event->setDamage($event->getDamage() + $damage);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::GOOEY);
            if ($enchantment !== null) {
                $task = new GoeyTask($this->plugin, $entity, $enchantment->getLevel());
                $this->plugin->getServer()->getScheduler()->scheduleDelayedTask($task, 1);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::POISON);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::POISON);
                $effect->setAmplifier($enchantment->getLevel());
                $effect->setDuration(60 * $enchantment->getLevel());
                $effect->setVisible(false);
                $entity->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::CRIPPLINGSTRIKE);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::NAUSEA);
                $effect->setAmplifier(0);
                $effect->setDuration(100 * $enchantment->getLevel());
                $effect->setVisible(false);
                $entity->addEffect($effect);
                $effect = Effect::getEffect(Effect::SLOWNESS);
                $effect->setAmplifier($enchantment->getLevel());
                $effect->setDuration(100 * $enchantment->getLevel());
                $effect->setVisible(false);
                $entity->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::VAMPIRE);
            if ($enchantment !== null) {
                if (!isset($this->plugin->vampirecd[strtolower($damager->getName())]) || time() > $this->plugin->vampirecd[strtolower($damager->getName())]) {
                    $this->plugin->vampirecd[strtolower($damager->getName())] = time() + 5;
                    if ($damager->getHealth() + ($event->getDamage() / 2) <= $damager->getMaxHealth()) {
                        $damager->setHealth($damager->getHealth() + ($event->getDamage() / 2));
                    } else {
                        $damager->setHealth($damager->getMaxHealth());
                    }
                    if ($damager->getFood() + ($event->getDamage() / 2) <= $damager->getMaxFood()) {
                        $damager->setFood($damager->getFood() + ($event->getDamage() / 2));
                    } else {
                        $damager->setFood($damager->getMaxFood());
                    }
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::CHARGE);
            if ($enchantment !== null) {
                if ($damager->isSprinting()) {
                    $event->setDamage($event->getDamage() * (1 + 0.10 * $enchantment->getLevel()));
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::AERIAL);
            if ($enchantment !== null) {
                if (!$damager->isOnGround()) {
                    $event->setDamage($event->getDamage() * (1 + 0.10 * $enchantment->getLevel()));
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::WITHER);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::WITHER);
                $effect->setAmplifier($enchantment->getLevel());
                $effect->setDuration(60 * $enchantment->getLevel());
                $effect->setVisible(false);
                $entity->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::DISARMING);
            if ($enchantment !== null) {
                if ($entity instanceof Player) {
                    $item = $entity->getInventory()->getItemInHand();
                    $entity->getInventory()->removeItem($item);
                    $motion = $entity->getDirectionVector()->multiply(0.4);
                    $entity->getLevel()->dropItem($entity->add(0, 1.3, 0), $item, $motion, 40);
                }
            }
        }
        if ($event instanceof PlayerDeathEvent) {
            $drops = $event->getDrops();
            $soulbounded = [];
            foreach ($damager->getInventory()->getContents() as $k => $item) {
                $enchantment = $this->plugin->getEnchantment($item, CustomEnchants::SOULBOUND);
                if ($enchantment !== null) {
                    $index = array_search($item, $drops);
                    if ($index !== false) {
                        unset($drops[$index]);
                    }
                    array_push($soulbounded, $this->plugin->removeEnchantment($item, $enchantment, $damager, $k));
                }
            }
            $event->setDrops([]);
            $event->setKeepInventory(true);
            foreach ($drops as $drop) {
                $damager->getLevel()->dropItem($damager, $drop);
            }
            $damager->getInventory()->setContents($soulbounded);
        }
        if ($event instanceof PlayerMoveEvent) {
            foreach ($damager->getInventory()->getContents() as $slot => $item) {
                $enchantment = $this->plugin->getEnchantment($item, CustomEnchants::AUTOREPAIR);
                if ($enchantment !== null) {
                    $newDir = $item->getDamage() - (1 + (1 * $enchantment->getLevel()));
                    if ($newDir < 0) {
                        $item->setDamage(0);
                    } else {
                        $item->setDamage($newDir);
                    }
                    $damager->getInventory()->setItem($slot, $item);
                }
            }
        }
    }

    /**
     * @param Player $player
     * @param BlockEvent $event
     */
    public function checkToolEnchants(Player $player, BlockEvent $event)
    {
        if ($event instanceof BlockBreakEvent) {
            $block = $event->getBlock();
            $drops = $event->getDrops();
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::EXPLOSIVE);
            if ($enchantment !== null) {
                $explosion = new Explosion($block, $enchantment->getLevel() * 5, $player);
                $explosion->explodeA();
                $explosion->explodeB();
            }
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::SMELTING);
            if ($enchantment !== null) {
                $finaldrop = array();
                $otherdrops = array();
                foreach ($drops as $drop) {
                    switch ($drop->getId()) {
                        case Item::COBBLESTONE:
                            array_push($finaldrop, Item::get(Item::STONE, 0, $drop->getCount()));
                            break;
                        case Item::IRON_ORE:
                            array_push($finaldrop, Item::get(Item::IRON_INGOT, 0, $drop->getCount()));
                            break;
                        case Item::GOLD_ORE:
                            array_push($finaldrop, Item::get(Item::GOLD_INGOT, 0, $drop->getCount()));
                            break;
                        case Item::SAND:
                            array_push($finaldrop, Item::get(Item::GLASS, 0, $drop->getCount()));
                            break;
                        case Item::CLAY:
                            array_push($finaldrop, Item::get(Item::BRICK, 0, $drop->getCount()));
                            break;
                        case Item::NETHERRACK:
                            array_push($finaldrop, Item::get(Item::NETHER_BRICK, 0, $drop->getCount()));
                            break;
                        case Item::STONE_BRICK:
                            if ($drop->getDamage() == 0) {
                                array_push($finaldrop, Item::get(Item::STONE_BRICK, 2, $drop->getCount()));
                            }
                            break;
                        case Item::CACTUS:
                            array_push($finaldrop, Item::get(Item::DYE, 2, $drop->getCount()));
                            break;
                        case Item::WOOD:
                        case Item::WOOD2:
                            array_push($finaldrop, Item::get(Item::COAL, 1, $drop->getCount()));
                            break;
                        case Item::SPONGE:
                            if ($drop->getDamage() == 1) {
                                array_push($finaldrop, Item::get(Item::SPONGE, 0, $drop->getCount()));
                            }
                            break;
                        default:
                            array_push($otherdrops, $drop);
                            break;
                    }
                }
                $event->setDrops(array_merge($finaldrop, $otherdrops));
            }
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::ENERGIZING);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::HASTE);
                $effect->setAmplifier(1 + $enchantment->getLevel() - 2);
                $effect->setDuration(20);
                $effect->setVisible(false);
                $player->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::QUICKENING);
            if ($enchantment !== null) {
                $effect = Effect::getEffect(Effect::SPEED);
                $effect->setAmplifier(3 + $enchantment->getLevel() - 2);
                $effect->setDuration(40);
                $effect->setVisible(false);
                $player->addEffect($effect);
            }
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::LUMBERJACK);
            if ($enchantment !== null) {
                if ($player->isSneaking()) {
                    if ($block->getId() == Block::WOOD || $block->getId() == Block::WOOD2) {
                        if (!isset($this->plugin->breakingTree[strtolower($player->getName())]) || $this->plugin->breakingTree[strtolower($player->getName())] < time()) {
                            $this->plugin->mined[strtolower($player->getName())] = 0;
                            $this->breakTree($block, $player);
                        }
                    }
                }
            }
            $enchantment = $this->plugin->getEnchantment($player->getInventory()->getItemInHand(), CustomEnchants::TELEPATHY);
            if ($enchantment !== null) {
                foreach ($drops as $drop) {
                    $player->getInventory()->addItem($drop);
                }
                $event->setDrops([]);
            }
        }
    }

    /**
     * @param Player $damager
     * @param Entity $entity
     * @param EntityEvent $event
     */
    public function checkBowEnchants(Player $damager, Entity $entity, EntityEvent $event)
    {
        if ($event instanceof EntityDamageByChildEntityEvent) {
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::MOLOTOV);
            if ($enchantment !== null) {
                $boundaries = 0.1 * $enchantment->getLevel();
                for ($x = $boundaries; $x >= -$boundaries; $x -= 0.1) {
                    for ($z = $boundaries; $z >= -$boundaries; $z -= 0.1) {
                        $entity->getLevel()->setBlock($entity->add(0, 1), Block::get(Block::FIRE));
                        $fire = Entity::createEntity("FallingSand", $entity->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $entity->x + 0.5), new DoubleTag("", $entity->y + 1), new DoubleTag("", $entity->z + 0.5)]), "Motion" => new ListTag("Motion", [new DoubleTag("", $x), new DoubleTag("", 0.1), new DoubleTag("", $z)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", 0), new FloatTag("", 0)]), "TileID" => new IntTag("TileID", 51), "Data" => new ByteTag("Data", 0)]));
                        $fire->spawnToAll();
                    }
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::PIERCING);
            if ($enchantment !== null) {
                $event->setDamage(0, EntityDamageEvent::MODIFIER_ARMOR);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::SHUFFLE);
            if ($enchantment !== null) {
                $pos1 = clone $damager->getPosition();
                $pos2 = clone $entity->getPosition();
                $damager->teleport($pos2);
                $entity->teleport($pos1);
                $name = $entity->getNameTag();
                if ($entity instanceof Player) {
                    $name = $entity->getDisplayName();
                    $entity->sendMessage(TextFormat::DARK_PURPLE . "You have switched positions with " . $damager->getDisplayName());
                }
                $damager->sendMessage(TextFormat::DARK_PURPLE . "You have switched positions with " . $name);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::HEALING);
            if ($enchantment !== null) {
                if ($entity->getHealth() + $event->getDamage() + $enchantment->getLevel() <= $entity->getMaxHealth()) {
                    $entity->setHealth($entity->getHealth() + $event->getDamage() + $enchantment->getLevel());
                } else {
                    $entity->setHealth($entity->getMaxHealth());
                }
                $event->setDamage(0);
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::HEADHUNTER);
            if ($enchantment !== null) {
                $projectile = $event->getChild();
                if ($projectile->y > $entity->getPosition()->y + $entity->getEyeHeight()) {
                    $event->setDamage($event->getDamage() * (1 + 0.10 * $enchantment->getLevel()));
                }
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::GRAPPLING);
            if ($enchantment !== null) {
                $task = new GrapplingTask($this->plugin, $damager->getPosition(), $entity);
                $this->plugin->getServer()->getScheduler()->scheduleDelayedTask($task, 1); //Delayed due to knockback interfering
            }
        }
        if ($event instanceof EntitySpawnEvent) {
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::BLAZE);
            if ($enchantment !== null && $entity instanceof Fireball !== true) {
                $fireball = Entity::createEntity("Fireball", $damager->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $entity->x), new DoubleTag("", $entity->y), new DoubleTag("", $entity->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", 0), new DoubleTag("", 0), new DoubleTag("", 0)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", $entity->yaw), new FloatTag("", $entity->pitch)])]), $damager);
                $fireball->setMotion($entity->getMotion());
                $fireball->spawnToAll();
                $entity->close();
                $entity = $fireball;
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::PORKIFIED);
            if ($enchantment !== null && $entity instanceof PigProjectile !== true) {
                $pig = Entity::createEntity("PigProjectile", $damager->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $entity->x), new DoubleTag("", $entity->y), new DoubleTag("", $entity->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", 0), new DoubleTag("", 0), new DoubleTag("", 0)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", $entity->yaw), new FloatTag("", $entity->pitch)])]), $damager, $enchantment->getLevel());
                $pig->setMotion($entity->getMotion());
                $pig->spawnToAll();
                $entity->close();
                $entity = $pig;
            }
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::VOLLEY);
            if ($enchantment !== null) {
                $amount = 1 + 2 * $enchantment->getLevel();
                $anglesbetweenarrows = (45 / ($amount - 1)) * M_PI / 180;
                $pitch = ($damager->getLocation()->getPitch() + 90) * M_PI / 180;
                $yaw = ($damager->getLocation()->getYaw() + 90 - 45 / 2) * M_PI / 180;
                $sZ = cos($pitch);
                for ($i = 0; $i < $amount; $i++) {
                    $nX = sin($pitch) * cos($yaw + $anglesbetweenarrows * $i);
                    $nY = sin($pitch) * sin($yaw + $anglesbetweenarrows * $i);
                    $newDir = new Vector3($nX, $sZ, $nY);
                    $projectile = null;
                    if ($entity instanceof Arrow) {
                        $projectile = Entity::createEntity("Arrow", $damager->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $damager->x), new DoubleTag("", $damager->y + $damager->getEyeHeight()), new DoubleTag("", $damager->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", 0), new DoubleTag("", 0), new DoubleTag("", 0)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", $damager->yaw), new FloatTag("", $damager->pitch)]), "Volley" => new ByteTag("Volley", 1)]), $damager);
                    }
                    if ($entity instanceof Fireball) {
                        $projectile = Entity::createEntity("Fireball", $damager->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $damager->x), new DoubleTag("", $damager->y + $damager->getEyeHeight()), new DoubleTag("", $damager->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", 0), new DoubleTag("", 0), new DoubleTag("", 0)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", $damager->yaw), new FloatTag("", $damager->pitch)]), "Volley" => new ByteTag("Volley", 1)]), $damager);
                    }
                    if ($entity instanceof PigProjectile) {
                        $projectile = Entity::createEntity("PigProjectile", $damager->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $damager->x), new DoubleTag("", $damager->y + $damager->getEyeHeight()), new DoubleTag("", $damager->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", 0), new DoubleTag("", 0), new DoubleTag("", 0)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", $damager->yaw), new FloatTag("", $damager->pitch)]), "Volley" => new ByteTag("Volley", 1)]), $damager, $entity->getPorkLevel());
                    }
                    $projectile->setMotion($newDir->normalize()->multiply($entity->getMotion()->length()));
                    $projectile->setOnFire($entity->fireTicks * 20);
                    $projectile->spawnToAll();
                }
                $entity->close();
            }
        }
        if ($event instanceof ProjectileHitEvent && $entity instanceof Projectile && $entity->hadCollision) {
            $enchantment = $this->plugin->getEnchantment($damager->getInventory()->getItemInHand(), CustomEnchants::GRAPPLING);
            if ($enchantment !== null) {
                $location = $entity->getPosition();
                $damagerloc = $damager->getPosition();
                if ($damager->distance($entity) < 6) {
                    if ($location->y > $damager->y) {
                        $damager->setMotion(new Vector3(0, 0.25, 0));
                    } else {
                        $v = $location->subtract($damagerloc);
                        $damager->setMotion($v);
                    }
                } else {
                    $g = -0.08;
                    $d = $location->distance($damagerloc);
                    $t = $d;
                    $v_x = (1.0 + 0.07 * $t) * ($location->x - $damagerloc->x) / $t;
                    $v_y = (1.0 + 0.03 * $t) * ($location->y - $damagerloc->y) / $t - 0.5 * $g * $t;
                    $v_z = (1.0 + 0.07 * $t) * ($location->z - $damagerloc->z) / $t;
                    $v = $damager->getMotion();
                    $v->setComponents($v_x, $v_y, $v_z);
                    $damager->setMotion($v);
                }
                $this->plugin->nofall[strtolower($damager->getName())] = time() + 1;
            }

        }
    }

    /**
     * @param Entity $entity
     * @param EntityEvent $event
     */
    public function checkArmorEnchants(Entity $entity, EntityEvent $event)
    {
        if ($entity instanceof Player) {
            $random = new Random();
            if ($event instanceof EntityArmorChangeEvent) {
                $olditem = $event->getOldItem();
                $newitem = $event->getNewItem();
                $slot = $event->getSlot();
                $enchantment = $this->plugin->getEnchantment($newitem, CustomEnchants::OBSIDIANSHIELD);
                if ($enchantment !== null) {
                    $effect = Effect::getEffect(Effect::FIRE_RESISTANCE);
                    $effect->setAmplifier(0);
                    $effect->setDuration(2147483647); //Effect wont show up for PHP_INT_MAX or it's value for 64 bit (I'm on 64 bit system), highest value i can use
                    $effect->setVisible(false);
                    $entity->addEffect($effect);
                }
                $enchantment = $this->plugin->getEnchantment($olditem, CustomEnchants::OBSIDIANSHIELD);
                if ($enchantment !== null) {
                    $entity->removeEffect(Effect::FIRE_RESISTANCE);
                }
                if ($slot == $entity->getInventory()->getSize() + 3) { //Boot slot
                    $enchantment = $this->plugin->getEnchantment($newitem, CustomEnchants::GEARS);
                    if ($enchantment !== null) {
                        $effect = Effect::getEffect(Effect::SPEED);
                        $effect->setAmplifier(0);
                        $effect->setDuration(2147483647); //Effect wont show up for PHP_INT_MAX or it's value for 64 bit (I'm on 64 bit system), highest value i can use
                        $effect->setVisible(false);
                        $entity->addEffect($effect);
                    }
                    $enchantment = $this->plugin->getEnchantment($olditem, CustomEnchants::GEARS);
                    if ($enchantment !== null) {
                        $entity->removeEffect(Effect::SPEED);
                    }
                    $enchantment = $this->plugin->getEnchantment($newitem, CustomEnchants::SPRINGS);
                    if ($enchantment !== null) {
                        $effect = Effect::getEffect(Effect::JUMP);
                        $effect->setAmplifier(3);
                        $effect->setDuration(2147483647); //Effect wont show up for PHP_INT_MAX or it's value for 64 bit (I'm on 64 bit system), highest value i can use
                        $effect->setVisible(false);
                        $entity->addEffect($effect);
                    }
                    $enchantment = $this->plugin->getEnchantment($olditem, CustomEnchants::SPRINGS);
                    if ($enchantment !== null) {
                        $entity->removeEffect(Effect::JUMP);
                    }
                }
                if ($slot == $entity->getInventory()->getSize()) { //Helmet slot
                    $enchantment = $this->plugin->getEnchantment($newitem, CustomEnchants::GLOWING);
                    if ($enchantment !== null) {
                        $effect = Effect::getEffect(Effect::NIGHT_VISION);
                        $effect->setAmplifier(0);
                        $effect->setDuration(2147483647); //Effect wont show up for PHP_INT_MAX or it's value for 64 bit (I'm on 64 bit system), highest value i can use
                        $effect->setVisible(false);
                        $entity->addEffect($effect);
                    }
                    $enchantment = $this->plugin->getEnchantment($olditem, CustomEnchants::GLOWING);
                    if ($enchantment !== null) {
                        $entity->removeEffect(Effect::NIGHT_VISION);
                    }
                }
            }
            if ($event instanceof EntityDamageEvent) {
                $damage = $event->getDamage();
                $cause = $event->getCause();
                if ($cause == EntityDamageEvent::CAUSE_FALL) {
                    $enchantment = $this->plugin->getEnchantment($entity->getInventory()->getBoots(), CustomEnchants::STOMP);
                    if ($enchantment !== null) {
                        $entities = $entity->getLevel()->getNearbyEntities($entity->getBoundingBox());
                        foreach ($entities as $e) {
                            if ($entity === $e) {
                                continue;
                            }
                            $ev = new EntityDamageByEntityEvent($entity, $e, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage / 2);
                            $this->plugin->getServer()->getPluginManager()->callEvent($ev);
                            $e->attack($damage / 2, $ev);
                        }
                        if (count($entities) > 1) {
                            $event->setDamage($event->getDamage() / 4);
                        }
                    }
                }
                foreach ($entity->getInventory()->getArmorContents() as $slot => $armor) {
                    $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::ENDERSHIFT);
                    if ($enchantment !== null) {
                        if ($entity->getHealth() - $event->getDamage() <= 4) {
                            if (!isset($this->plugin->endershiftcd[strtolower($entity->getName())]) || time() > $this->plugin->endershiftcd[strtolower($entity->getName())]) {
                                $this->plugin->endershiftcd[strtolower($entity->getName())] = time() + 300;
                                $effect = Effect::getEffect(Effect::SPEED);
                                $effect->setAmplifier($enchantment->getLevel() + 3);
                                $effect->setDuration(200 * $enchantment->getLevel());
                                $effect->setVisible(false);
                                $entity->addEffect($effect);
                                $effect = Effect::getEffect(Effect::ABSORPTION);
                                $effect->setAmplifier($enchantment->getLevel() + 3);
                                $effect->setDuration(200 * $enchantment->getLevel());
                                $effect->setVisible(false);
                                $entity->addEffect($effect);
                                $entity->sendMessage("You feel a rush of energy coming from your armor!");
                            }
                        }
                    }
                    $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::BERSERKER);
                    if ($enchantment !== null) {
                        if ($entity->getHealth() - $event->getDamage() <= 4) {
                            if (!isset($this->plugin->berserkercd[strtolower($entity->getName())]) || time() > $this->plugin->berserkercd[strtolower($entity->getName())]) {
                                $this->plugin->berserkercd[strtolower($entity->getName())] = time() + 300;
                                $effect = Effect::getEffect(Effect::STRENGTH);
                                $effect->setAmplifier(3 + $enchantment->getLevel());
                                $effect->setDuration(200 * $enchantment->getLevel());
                                $effect->setVisible(false);
                                $entity->addEffect($effect);
                                $entity->sendMessage("Your bloodloss makes your stronger!");
                            }
                        }
                    }
                    if ($event instanceof EntityDamageByEntityEvent) {
                        $damager = $event->getDamager();
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::MOLTEN);
                        if ($enchantment !== null) {
                            $damager->setOnFire(3 * $enchantment->getLevel());
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::ENLIGHTED);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::REGENERATION);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $entity->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::HARDENED);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::WEAKNESS);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::POISONED);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::POISON);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::FROZEN);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::SLOWNESS);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::REVULSION);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::NAUSEA);
                            $effect->setAmplifier(0);
                            $effect->setDuration(20 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::CURSED);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::WITHER);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::DRUNK);
                        if ($enchantment !== null) {
                            $effect = Effect::getEffect(Effect::SLOWNESS);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                            $effect = Effect::getEffect(Effect::MINING_FATIGUE);
                            $effect->setAmplifier($enchantment->getLevel());
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                            $effect = Effect::getEffect(Effect::NAUSEA);
                            $effect->setAmplifier(0);
                            $effect->setDuration(60 * $enchantment->getLevel());
                            $effect->setVisible(false);
                            $damager->addEffect($effect);
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::CLOAKING);
                        if ($enchantment !== null) {
                            if (!isset($this->plugin->cloakingcd[strtolower($entity->getName())]) || time() > $this->plugin->cloakingcd[strtolower($entity->getName())]) {
                                $this->plugin->cloakingcd[strtolower($entity->getName())] = time() + 10;
                                $effect = Effect::getEffect(Effect::INVISIBILITY);
                                $effect->setAmplifier(0);
                                $effect->setDuration(60 * $enchantment->getLevel());
                                $effect->setVisible(false);
                                $entity->addEffect($effect);
                                $entity->sendMessage(TextFormat::DARK_GRAY . "You have become invisible!");
                            }
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::SELFDESTRUCT);
                        if ($enchantment !== null) {
                            if ($event->getDamage() >= $entity->getHealth()) { //Compatibility for plugins that auto respawn players on death
                                for ($i = $enchantment->getLevel(); $i >= 0; $i--) {
                                    $tnt = Entity::createEntity("PrimedTNT", $entity->getLevel(), new CompoundTag("", ["Pos" => new ListTag("Pos", [new DoubleTag("", $entity->x), new DoubleTag("", $entity->y), new DoubleTag("", $entity->z)]), "Motion" => new ListTag("Motion", [new DoubleTag("", $random->nextFloat() * 1.5 - 1), new DoubleTag("", $random->nextFloat() * 1.5), new DoubleTag("", $random->nextFloat() * 1.5 - 1)]), "Rotation" => new ListTag("Rotation", [new FloatTag("", 0), new FloatTag("", 0)]), "Fuse" => new ByteTag("Fuse", 40)]));
                                    $tnt->spawnToAll();
                                }
                            }
                        }
                        $enchantment = $this->plugin->getEnchantment($armor, CustomEnchants::REVIVE);
                        if ($enchantment !== null) {
                            if ($event->getDamage() >= $entity->getHealth()) {
                                $entity->getInventory()->setArmorItem($slot, $this->plugin->removeEnchantment($armor, $enchantment, $entity, $slot));
                                $entity->removeAllEffects();
                                $entity->setHealth($entity->getMaxHealth());
                                $entity->setFood($entity->getMaxFood());
                                $event->setDamage(0);
                                //TODO: Side effect
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param Block $block
     * @param Player $player
     * @param Block|null $oldblock
     */
    public function breakTree(Block $block, Player $player, Block $oldblock = null)
    {
        $item = $player->getInventory()->getItemInHand();
        for ($i = 0; $i <= 5; $i++) {
            if ($this->plugin->mined[strtolower($player->getName())] > 800) {
                break;
            }
            $this->plugin->breakingTree[strtolower($player->getName())] = time() + 1;
            $side = $block->getSide($i);
            if ($oldblock !== null) {
                if ($side->equals($oldblock)) {
                    continue;
                }
            }
            if ($side->getId() !== Block::WOOD && $side->getId() !== Block::WOOD2) {
                continue;
            }
            $player->getLevel()->useBreakOn($side, $item, $player);
            $this->plugin->mined[strtolower($player->getName())]++;
            $this->breakTree($side, $player, $block);
        }
    }
}