<?php

namespace Drupal\wsc_analytics\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wsc_analytics\Service\WscCoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure WSC Analytics settings for Drupal.
 */
class WscSettingsForm extends ConfigFormBase {

  /** @var \Drupal\wsc_analytics\Service\WscCoreService */
  protected $core;

  public function __construct(WscCoreService $core) {
    $this->core = $core;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wsc_analytics.core')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['wsc_analytics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wsc_analytics_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wsc_analytics.settings');
    $creds  = $this->core->getProjectCredentials();

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Tracking Configuration'),
      '#open' => true,
    ];

    $form['general']['tracking_active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WSC Analytics Tracking'),
      '#default_value' => $config->get('tracking_active') ?? true,
      '#description' => $this->t('Injects the privacy-first client-side tracker into pages and activates edge crawler sensor.'),
    ];

    $form['general']['tracking_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Tracking Mode'),
      '#options' => [
        'cookieless' => $this->t('Cookieless Privacy-First (Default - 100% GDPR/ePrivacy compliant without consent banner)'),
        'consent'    => $this->t('Consent-Required (Only tracks when user grants consent via CMP / Cookie Banner)'),
      ],
      '#default_value' => $config->get('tracking_mode') ?: 'cookieless',
    ];

    $form['general']['exclude_admins'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude Administrators from Tracking'),
      '#default_value' => $config->get('exclude_admins') ?? true,
      '#description' => $this->t('Do not track pageviews and actions of logged-in administrators.'),
    ];

    // --- Gateway & Cloud Sync ---
    $form['gateway'] = [
      '#type' => 'details',
      '#title' => $this->t('WSC Gateway & Hybrid Encryption'),
      '#open' => true,
    ];

    $form['gateway']['hybrid_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Sync with WSC Gateway'),
      '#default_value' => $config->get('hybrid_enabled') ?? true,
      '#description' => $this->t('Synchronizes encrypted micro-batches to the WSC Gateway (ClickHouse analytics backend).'),
    ];

    $form['gateway']['gateway_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gateway API URL'),
      '#default_value' => $config->get('gateway_url') ?: WscCoreService::GATEWAY_API_DEFAULT,
      '#required' => true,
    ];

    $form['gateway']['project_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Project Credentials'),
      '#markup' => '<p><strong>Project ID:</strong> <code>' . htmlspecialchars($creds['project_id']) . '</code><br><strong>Local AES Key:</strong> <code>' . substr($creds['local_key'], 0, 8) . '...' . substr($creds['local_key'], -8) . '</code> (Stored securely on this server)</p>',
    ];

    // --- Bot Shield ---
    $form['bots'] = [
      '#type' => 'details',
      '#title' => $this->t('AI & Search Bot Shield Sensor'),
      '#open' => true,
    ];

    $form['bots']['bot_tracking_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Bot Tracking Scope'),
      '#options' => [
        'off'       => $this->t('Disabled'),
        'ai_only'   => $this->t('AI / LLM Bots only (GPTBot, Claude, Perplexity, etc.)'),
        'search_ai' => $this->t('Search Engines + AI Bots (Recommended)'),
        'all'       => $this->t('All known crawlers & SEO tools'),
      ],
      '#default_value' => $config->get('bot_tracking_level') ?: 'search_ai',
    ];

    // --- Automated Interactions ---
    $form['interactions'] = [
      '#type' => 'details',
      '#title' => $this->t('Automated User Interactions'),
      '#open' => false,
    ];

    $form['interactions']['auto_downloads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track file downloads (PDF, ZIP, DOCX, etc.)'),
      '#default_value' => $config->get('auto_downloads') ?? false,
    ];

    $form['interactions']['auto_media'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track HTML5 video & audio playback'),
      '#default_value' => $config->get('auto_media') ?? false,
    ];

    $form['interactions']['auto_outbound'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track outbound link clicks'),
      '#default_value' => $config->get('auto_outbound') ?? false,
    ];

    $form['interactions']['auto_forms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track form submissions (Drupal webforms, contact forms)'),
      '#default_value' => $config->get('auto_forms') ?? false,
    ];

    $form['interactions']['auto_scroll'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track scroll depth (25%, 50%, 75%, 100%)'),
      '#default_value' => $config->get('auto_scroll') ?? false,
    ];

    $form['interactions']['auto_time_on_page'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track active engaged time on page'),
      '#default_value' => $config->get('auto_time_on_page') ?? false,
    ];

    $form['interactions']['auto_time_to_interaction'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track time to first user interaction'),
      '#default_value' => $config->get('auto_time_to_interaction') ?? false,
    ];

    $form['interactions']['auto_video_progress'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track video watch milestones (25%, 50%, 75%, 100%)'),
      '#default_value' => $config->get('auto_video_progress') ?? false,
    ];

    $form['interactions']['auto_form_field_timing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-track form field timing (time to first input)'),
      '#default_value' => $config->get('auto_form_field_timing') ?? false,
    ];

    // --- Retention ---
    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Data Retention & Maintenance'),
      '#open' => false,
    ];

    $form['retention']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Local Queue Retention (Days)'),
      '#default_value' => $config->get('retention_days') ?: 30,
      '#min' => 1,
      '#max' => 365,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('wsc_analytics.settings')
      ->set('tracking_active', (bool) $form_state->getValue('tracking_active'))
      ->set('tracking_mode', $form_state->getValue('tracking_mode'))
      ->set('exclude_admins', (bool) $form_state->getValue('exclude_admins'))
      ->set('hybrid_enabled', (bool) $form_state->getValue('hybrid_enabled'))
      ->set('gateway_url', $form_state->getValue('gateway_url'))
      ->set('bot_tracking_level', $form_state->getValue('bot_tracking_level'))
      ->set('auto_downloads', (bool) $form_state->getValue('auto_downloads'))
      ->set('auto_media', (bool) $form_state->getValue('auto_media'))
      ->set('auto_outbound', (bool) $form_state->getValue('auto_outbound'))
      ->set('auto_forms', (bool) $form_state->getValue('auto_forms'))
      ->set('auto_scroll', (bool) $form_state->getValue('auto_scroll'))
      ->set('auto_time_on_page', (bool) $form_state->getValue('auto_time_on_page'))
      ->set('auto_time_to_interaction', (bool) $form_state->getValue('auto_time_to_interaction'))
      ->set('auto_video_progress', (bool) $form_state->getValue('auto_video_progress'))
      ->set('auto_form_field_timing', (bool) $form_state->getValue('auto_form_field_timing'))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
