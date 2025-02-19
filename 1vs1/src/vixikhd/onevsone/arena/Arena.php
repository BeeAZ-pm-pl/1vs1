<?php

/**
 * Copyright 2018-2019 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\onevsone\arena;

use pocketmine\block\Block;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\block\Chest;
use pocketmine\world\World;
use pocketmine\player\GameMode;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\block\tile\Tile;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\effect\EffectManager;
use vixikhd\onevsone\event\PlayerArenaWinEvent;
use vixikhd\onevsone\event\PlayerEquipEvent;
use vixikhd\onevsone\math\Vector3;
use vixikhd\onevsone\OneVsOne;
use pocketmine\Server;
use vixikhd\onevsone\arena\ArenaScheduler;

/**
 * Class Arena
 * @package onevsone\arena
 */
class Arena implements Listener {

    const MSG_MESSAGE = 0;
    const MSG_TIP = 1;
    const MSG_POPUP = 2;
    const MSG_TITLE = 3;

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    /** @var OneVsOne $plugin */
    public OneVsOne $plugin;

    /** @var ArenaScheduler $scheduler */
    public ArenaScheduler $scheduler;

    /** @var int $phase */
    public int $phase = 0;

    /** @var array $data */
    public array $data = [];

    /** @var bool $setting */
    public bool $setup = false;

    /** @var Player[] $players */
    public array $players = [];

    /** @var Player[] $toRespawn */
    public array $toRespawn = [];

    /** @var World $level */
    public ?World $level = null;

    /** @var string $kit */
    public string $kit;

    /**
     * Arena constructor.
     * @param OneVsOne $plugin
     * @param array $arenaFileData
     */
    public function __construct(OneVsOne $plugin, array $arenaFileData) {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(false);
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ArenaScheduler($this), 20);
        if($this->setup) {
            if(empty($this->data)) {
                $this->createBasicData();
            }
        }
        else {
            $this->loadArena();
        }
    }

    /**
     * @param Player $player
     */
    public function joinToArena(Player $player) {
        if(!$this->data["enabled"]) {
            $player->sendMessage("§c> Arena is under setup!");
            return;
        }

        if(count($this->players) >= $this->data["slots"]) {
            $player->sendMessage("§c> Arena is full!");
            return;
        }

        if($this->inGame($player)) {
            $player->sendMessage("§c> You are already in queue!");
            return;
        }

        $selected = false;
        for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
            if(!$selected) {
                if(!isset($this->players[$index = "spawn-{$lS}"])) {
                    $player->teleport(Position::fromObject(Vector3::fromString($this->data["spawns"][$index]), $this->level));
                    $this->players[$index] = $player;
                    $selected = true;
                }
            }
        }

        $this->broadcastMessage("§a> Player {$player->getName()} joined the match! §7[".count($this->players)."/{$this->data["slots"]}]");

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->setGamemode(GameMode::ADVENTURE());
        $player->setHealth(20);
        $player->getHungerManager()->setFood(20);

        $inv = $player->getArmorInventory();
        if(empty($this->plugin->dataProvider->config["kits"]) || !is_array($this->plugin->dataProvider->config["kits"]) || $this->kit == null) {
            $inv->setHelmet(VanillaItems::DIAMOND_HELMET());
            $inv->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
            $inv->setLeggings(VanillaItems::DIAMOND_LEGGINGS());
            $inv->setBoots(VanillaItems::DIAMOND_BOOTS());

            $player->getInventory()->addItem(VanillaItems::IRON_SWORD());
            $player->getInventory()->addItem(VanillaItems::GOLDEN_APPLE()->setCount(5));
            $event = new PlayerEquipEvent($this->plugin, $player, $this);
            $event->call();
            return;
        }


        $kitData = $this->plugin->dataProvider->config["kits"][$this->kit];
        if(isset($kitData["helmet"])) $inv->setHelmet(ItemFactory::getInstance()->get($kitData["helmet"][0], $kitData["helmet"][1], $kitData["helmet"][2]));
        if(isset($kitData["chestplate"])) $inv->setChestplate(ItemFactory::getInstance()->get($kitData["chestplate"][0], $kitData["chestplate"][1], $kitData["chestplate"][2]));
        if(isset($kitData["leggings"])) $inv->setLeggings(ItemFactory::getInstance()->get($kitData["leggings"][0], $kitData["leggings"][1], $kitData["leggings"][2]));
        if(isset($kitData["boots"])) $inv->setBoots(ItemFactory::getInstance()->get($kitData["boots"][0], $kitData["boots"][1], $kitData["boots"][2]));

        foreach ($kitData as $slot => [$id, $damage, $count]) {
            if(is_numeric($slot)) {
                $slot = (int)$slot;
                $player->getInventory()->setItem($slot, ItemFactory::getInstance()->get($id, $damage, $count)); 
            }
        }

        $event = new PlayerEquipEvent($this->plugin, $player, $this);
        $event->call();
    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     */
    public function disconnectPlayer(Player $player, string $quitMsg = "", bool $death = \false) {
        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
                $index = "";
                foreach ($this->players as $i => $p) {
                    if($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if($index != "") {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }

        $player->getEffects()->clear();

        $player->setGamemode($this->plugin->getServer()->getGameMode());

        $player->setHealth(20);
        $player->getHungerManager()->setFood(20);

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());

        if(!$death) {
            $this->broadcastMessage("§a> Player {$player->getName()} left the match. §7[".count($this->players)."/{$this->data["slots"]}]",self::MSG_MESSAGE);
        }

        if($quitMsg != "") {
            $player->sendMessage("§a> $quitMsg");
        }
    }

    public function startGame() {
        $players = [];
        foreach ($this->players as $player) {
            $players[$player->getName()] = $player;
        }


        $this->players = $players;
        $this->phase = 1;

        $this->broadcastMessage("Match Started!", self::MSG_TITLE);
    }

    public function startRestart() {
        $player = null;
        foreach ($this->players as $p) {
            $player = $p;
        }

        if($player === null || (!$player instanceof Player) || (!$player->isOnline())) {
            $this->phase = self::PHASE_RESTART;
            return;
        }

        $player->sendTitle("§aYOU WON!");
        $ev = new PlayerArenaWinEvent($this->plugin, $player, $this);
        $ev->call();
        $this->plugin->getServer()->broadcastMessage("§a[1vs1] Player {$player->getName()} won the match at {$this->level->getFolderName()}!");
        $this->phase = self::PHASE_RESTART;
    }

    /**
     * @param Player $player
     * @return bool $isInGame
     */
    public function inGame(Player $player): bool {
        switch ($this->phase) {
            case self::PHASE_LOBBY:
                $inGame = false;
                foreach ($this->players as $players) {
                    if($players->getId() == $player->getId()) {
                        $inGame = true;
                    }
                }
                return $inGame;
            default:
                return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     */
    public function broadcastMessage(string $message, int $id = 0, string $subMessage = "") {
        foreach ($this->players as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->sendTitle($message, $subMessage);
                    break;
            }
        }
    }

    /**
     * @return bool $end
     */
    public function checkEnd(): bool {
        return count($this->players) <= 1;
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) {
        if($this->phase != self::PHASE_LOBBY) return;
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            $index = null;
            foreach ($this->players as $i => $p) {
                if($p->getId() == $player->getId()) {
                    $index = $i;
                }
            }
            if($event->getPlayer()->getPosition()->distance(Vector3::fromString($this->data["spawns"][$index])) > 1) {
                // $event->cancel() will not work
                $player->teleport(Vector3::fromString($this->data["spawns"][$index]));
            }
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();

        if(!$player instanceof Player) return;

        if($this->inGame($player) && $this->phase == self::PHASE_LOBBY && !$this->plugin->dataProvider->config["hunger"]) {
            $event->cancel();
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        
        if($this->inGame($player) && $event->getBlock() instanceof CHEST && $this->phase == self::PHASE_LOBBY) {
            $event->cancel();
            return;
        }

        if(!$block->getPosition()->getWorld()->getTile($block->getPosition()) instanceof Tile) {
            return;
        }

        $signPos = Position::fromObject(Vector3::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block->getPosition())) || $signPos->getWorld()->getId() != $block->getPosition()->getWorld()->getId()) {
            return;
        }

        if($this->phase == self::PHASE_GAME) {
            $player->sendMessage("§c> Arena is in-game");
            return;
        }
        if($this->phase == self::PHASE_RESTART) {
            $player->sendMessage("§c> Arena is restarting!");
            return;
        }

        if($this->setup) {
            return;
        }

        $this->joinToArena($player);
    }

    /**
     * @param PlayerDeathEvent $event
     */
    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();

        if(!$this->inGame($player)) return;

        foreach ($event->getDrops() as $item) {
            $player->getWorld()->dropItem($player, $item);
        }
        $this->toRespawn[$player->getName()] = $player;
        $this->disconnectPlayer($player, "", true);
        $this->broadcastMessage("§a> {$this->plugin->getServer()->getLanguage()->translate($event->getDeathMessage())} §7[".count($this->players)."/{$this->data["slots"]}]");
        $event->setDeathMessage("");
        $event->setDrops([]);
    }

    /**
     * @param PlayerRespawnEvent $event
     */
    public function onRespawn(PlayerRespawnEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->toRespawn[$player->getName()])) {
            $event->setRespawnPosition($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
            unset($this->toRespawn[$player->getName()]);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) {
        if($this->inGame($event->getPlayer())) {
            $this->disconnectPlayer($event->getPlayer());
        }
    }

    /**
     * @param EntityTeleportEvent $event
     */
    public function onTeleport(EntityTeleportEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        if($player->getWorld()->getFolderName() === ($this->data["level"])) return;
        if($this->inGame($player)) {
            $this->disconnectPlayer($player, "You are successfully leaved arena!");
        }
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false) {
        if(!$this->data["enabled"]) {
            $this->plugin->getLogger()->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if(!$restart) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["level"])) {
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["level"]);
            }

            $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);
        }

        else {
            $this->scheduler->reloadTimer();
        }

        if(!$this->level instanceof World) $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);

        $keys = array_keys($this->plugin->dataProvider->config["kits"]);
        $this->kit = $keys[array_rand($keys, 1)];

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool {
        if(empty($this->data)) {
            return false;
        }
        if($this->data["level"] == null) {
            return false;
        }
        if(!$this->plugin->getServer()->getWorldManager()->isWorldGenerated($this->data["level"])) {
            return false;
        }
        else {
            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["level"]))
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["level"]);
            $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);
        }
        if(!is_int($this->data["slots"])) {
            return false;
        }
        if(!is_array($this->data["spawns"])) {
            return false;
        }
        if(count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if(!is_array($this->data["joinsign"])) {
            return false;
        }
        if(count($this->data["joinsign"]) !== 2) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena) $this->loadArena();
        return true;
    }

    private function createBasicData() {
        $this->data = [
            "level" => null,
            "slots" => 2,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => []
        ];
    }

    public function __destruct() {
        unset($this->scheduler);
    }
}
