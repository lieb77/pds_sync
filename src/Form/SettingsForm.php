<?php

declare(strict_types=1);

namespace Drupal\pds_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure pds_sync settings for this site.
 */
final class SettingsForm extends ConfigFormBase
{
    /**
     * The key factory.
     *
     * @var KeyRepositoryInterface
     */
    protected $keyRepository;

    final public function __construct(KeyRepositoryInterface $key_repository)
    {
        $this->keyRepository = $key_repository;
    }

    final public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('key.repository'),
        );
    }

    /*
    * {@inheritdoc}
    */
    public function getFormId(): string
    {
        return 'pds_sync_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['pds_sync.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('pds_sync.settings');

        $form['handle'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Bluesky Handle'),
        '#description' => $this->t('Bluesky handle, without the @.'),
        '#default_value' => $config->get('handle'),
        ];

        $form['app_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Bluesky App Key'),
        '#description' => $this->t('The App Key. Create this on your Bluesky profile, and then add to the key module.'),
        '#default_value' => $config->get('app_key'),
        ];
  
        return parent::buildForm($form, $form_state);
    }


    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        // Validate the api key against model listing.
        $key = $form_state->getValue('app_key');
        $app_key = $this->keyRepository->getKey($key)->getKeyValue();
        if (!$app_key) {
            $form_state->setErrorByName('app_key', $this->t('The App Key is invalid.'));
            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('pds_sync.settings')
            ->set('handle', $form_state->getValue('handle'))
            ->set('app_key', $form_state->getValue('app_key'))
            ->save();
        parent::submitForm($form, $form_state);
    }

}
