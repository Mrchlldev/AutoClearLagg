<?php
declare(strict_types=1);

namespace FiraAja\AutoClearLagg;

use pocketmine\entity\Human;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\Painting;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use function array_map;
use function in_array;
use function is_array;
use function is_numeric;
use function str_replace;
use function strtolower;

class AutoClearLagg extends PluginBase{

    public const LANG_TIME_LEFT = "time-left";
    public const LANG_ENTITIES_CLEARED = "entities-cleared";

    /** @var int */
    private int $interval;
    /** @var int */
    private int $seconds;

    /** @var bool */
    private bool $clearItems;
    /** @var bool */
    private bool $clearMobs;
    /** @var bool */
    private bool $clearXpOrbs;

    /** @var string[] */
    private array $exemptEntities;

    /** @var string[] */
    private array $messages;
    /** @var int[] */
    private array $broadcastTimes;

    public function onEnable() : void{
        $config = $this->getConfig()->getAll();

        if(!is_numeric($config["seconds"] ?? 300)){
            $this->getLogger()->error("Config error: seconds attribute must an integer");
            $this->getServer()->getPluginManager()->disablePlugin($this);

            return;
        }
        $this->interval = $this->seconds = $config["seconds"];

        if(!is_array($config["clear"] ?? [])){
            $this->getLogger()->error("Config error: clear attribute must an array");
            $this->getServer()->getPluginManager()->disablePlugin($this);

            return;
        }
        $clear = $config["clear"] ?? [];
        $this->clearItems = (bool) ($clear["items"] ?? false);
        $this->clearMobs = (bool) ($clear["mobs"] ?? false);
        $this->clearXpOrbs = (bool) ($clear["xp-orbs"] ?? false);
        if(!is_array($clear["exempt"] ?? [])){
            $this->getLogger()->error("Config error: clear.exempt attribute must an array");
            $this->getServer()->getPluginManager()->disablePlugin($this);

            return;
        }
        $this->exemptEntities = array_map(function($entity) : string{
            return strtolower((string) $entity);
        }, $clear["exempt"] ?? []);

        if(!is_array($config["messages"] ?? [])){
            $this->getLogger()->error("Config error: times attribute must an array");
            $this->getServer()->getPluginManager()->disablePlugin($this);

            return;
        }
        $messages = $config["messages"] ?? [];
        $this->messages = [
            self::LANG_TIME_LEFT => $messages[self::LANG_TIME_LEFT] ?? "§cEntities will clear in {SECONDS} seconds",
            self::LANG_ENTITIES_CLEARED => $messages[self::LANG_ENTITIES_CLEARED] ?? "§cCleared a total of {COUNT} entities"
        ];

        if(!is_array($config["times"] ?? [])){
            $this->getLogger()->error("Config error: times attribute must an array");
            $this->getServer()->getPluginManager()->disablePlugin($this);

            return;
        }
        $this->broadcastTimes = $config["times"] ?? [60, 30, 15, 10, 5, 4, 3, 2, 1];

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            if(--$this->seconds === 0){
                $entitiesCleared = 0;
                foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
                    foreach($world->getEntities() as $entity){
                        if($this->clearItems && $entity instanceof ItemEntity && $entity instanceof ExperienceOrb){
                            $entity->flagForDespawn();
                            ++$entitiesCleared;
                        } elseif($this->clearMobs && !$entity instanceof Human && !$entity instanceof Painting){
                            if(!in_array($entity, $this->exemptEntities)){
                                $entity->flagForDespawn();
                                ++$entitiesCleared;
                            }
                        }
                    }
                }
                if($this->messages[self::LANG_ENTITIES_CLEARED] !== ""){
                    foreach($this->getServer()->getOnlinePlayers() as $player){
                    	$player->sendMessage(str_replace("{COUNT}", (string) $entitiesCleared, $this->messages[self::LANG_ENTITIES_CLEARED]));
                        $this->sendSound($player, "random.levelup");
                    }
                }

                $this->seconds = $this->interval;
            } elseif(in_array($this->seconds, $this->broadcastTimes) && $this->messages[self::LANG_TIME_LEFT] !== ""){
                foreach($this->getServer()->getOnlinePlayers() as $player){
                	$player->sendMessage(str_replace("{SECONDS}", (string) $this->seconds, $this->messages[self::LANG_TIME_LEFT]));
                    $this->sendSound($player, "random.orb");
                }
            }
        }), 20);
    }

    public function sendSound(Player $player, string $sound, float $minimumVolume = 1.0, float $volume = 1.0, float $pitch = 1.0): void {
        $position = $player->getPosition();
        $pk = new PlaySoundPacket();
        $pk->soundName = $sound;
        $pk->volume = $volume > $minimumVolume ? $minimumVolume : $volume;
        $pk->pitch = $pitch;
        $pk->x = $position->x;
        $pk->y = $position->y;
        $pk->z = $position->z;
        $player->getNetworkSession()->sendDataPacket($pk);
    }
}
