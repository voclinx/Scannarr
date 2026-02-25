<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /**
     * Get a setting value by its key.
     */
    public function getValue(string $key): ?string
    {
        $setting = $this->findOneBy(['settingKey' => $key]);

        return $setting?->getSettingValue();
    }

    /**
     * Set a setting value (create or update).
     */
    public function setValue(string $key, ?string $value, string $type = 'string'): Setting
    {
        $setting = $this->findOneBy(['settingKey' => $key]);

        if ($setting === null) {
            $setting = new Setting();
            $setting->setSettingKey($key);
            $setting->setSettingType($type);
        }

        $setting->setSettingValue($value);

        $em = $this->getEntityManager();
        $em->persist($setting);
        $em->flush();

        return $setting;
    }

    /**
     * Get all settings as a key => value array.
     *
     * @return array<string, string|null>
     */
    public function getAllAsArray(): array
    {
        $settings = $this->findAll();
        $result = [];

        foreach ($settings as $setting) {
            $result[$setting->getSettingKey()] = $setting->getSettingValue();
        }

        return $result;
    }
}
