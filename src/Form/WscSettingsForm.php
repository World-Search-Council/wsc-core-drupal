<?php

namespace Drupal\wsc_analytics\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wsc_analytics\Service\WscCoreService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure WSC Analytics & Search Health settings for Drupal 8–11 (v2.2.3).
 */
class WscSettingsForm extends ConfigFormBase {

  /** @var \Drupal\wsc_analytics\Service\WscCoreService */
  protected $core;

  public function __construct(WscCoreService $core) {
    $this->core = $core;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wsc_analytics.core')
    );
  }

  protected function getEditableConfigNames() {
    return ['wsc_analytics.settings'];
  }

  public function getFormId() {
    return 'wsc_analytics_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wsc_analytics.settings');
    $creds  = $this->core->getProjectCredentials();

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Tracking & Zero-PII Privacy'),
      '#open' => true,
    ];

    $form['general']['tracking_active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WSC Analytics Tracking'),
      '#default_value' => $config->get('tracking_active') ?? true,
      '#description' => $this->t('Injects the cookieless client-side tracker and activates pre-cache AI crawler detection.'),
    ];

    $form['general']['tracking_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Tracking Mode'),
      '#options' => [
        'cookieless' => $this->t('Cookieless Zero-PII (Default — SHA256(IP + UA + DailySalt), zero raw IPs stored)'),
        'consent'    => $this->t('Consent-Required (Only tracks when visitor grants consent via CMP)'),
      ],
      '#default_value' => $config->get('tracking_mode') ?: 'cookieless',
    ];

    $form['general']['exclude_admins'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude Administrators from Tracking'),
      '#default_value' => $config->get('exclude_admins') ?? true,
    ];

    // --- Opt-In Gateway & Cloud Sync ---
    $form['gateway'] = [
      '#type' => 'details',
      '#title' => $this->t('WSC Gateway & Hybrid Encryption (Opt-In)'),
      '#open' => true,
    ];

    $form['gateway']['hybrid_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Opt-In Hybrid Sync with WSC Gateway'),
      '#default_value' => (bool) ($config->get('hybrid_enabled') ?? false),
      '#description' => $this->t('Opt-in to register via /v1/register and synchronize AES-256-CBC + HMAC-SHA256 micro-batches with api.worldsearchcouncil.org.'),
    ];

    $form['gateway']['gateway_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gateway API URL'),
      '#default_value' => $config->get('gateway_url') ?: WscCoreService::GATEWAY_API_DEFAULT,
      '#required' => true,
    ];

    $form['gateway']['project_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Project & IndexNow Credentials'),
      '#markup' => '<p><strong>Project ID:</strong> <code>' . htmlspecialchars($creds['project_id']) . '</code><br>'
        . '<strong>Local AES Key:</strong> <code>' . substr($creds['local_key'], 0, 8) . '••••' . substr($creds['local_key'], -8) . '</code><br>'
        . '<strong>IndexNow Key:</strong> <code>' . htmlspecialchars($creds['indexnow_key']) . '</code></p>',
    ];

    // --- AI Readiness, Benchmarks & IndexNow ---
    $form['ai_readiness'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Readiness (/llms.txt), Benchmarks & Opt-In IndexNow'),
      '#open' => true,
    ];

    $form['ai_readiness']['industry_vertical'] = [
      '#type' => 'select',
      '#title' => $this->t('Industry Benchmark Vertical'),
      '#options' => [
        '/Business & Industrial/Services' => $this->t('Business & Industrial Services'),
        '/Computers & Electronics'        => $this->t('Computers, Cloud & Electronics'),
        '/Shopping/Apparel'               => $this->t('E-Commerce & Retail'),
        '/Finance'                        => $this->t('Finance & Insurance'),
        '/Health'                         => $this->t('Health & Medical'),
        '/News'                           => $this->t('News, Media & Publishing'),
        '/Internet & Telecom'             => $this->t('Internet, Agency & Telecom'),
      ],
      '#default_value' => $config->get('industry_vertical') ?: '/Business & Industrial/Services',
    ];

    $form['ai_readiness']['llms_txt_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve Dynamic /llms.txt AI Manifest'),
      '#default_value' => (bool) ($config->get('llms_txt_enabled') ?? true),
    ];

    $form['ai_readiness']['llms_txt_summary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom /llms.txt Site Summary'),
      '#default_value' => $config->get('llms_txt_summary') ?: '',
    ];

    $form['ai_readiness']['indexnow_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Opt-In IndexNow Automatic Node Push'),
      '#default_value' => (bool) ($config->get('indexnow_enabled') ?? false),
      '#description' => $this->t('Automatically notifies api.indexnow.org when published Drupal nodes are created or updated.'),
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

    foreach ([
      'auto_downloads' => 'Auto-track file downloads (PDF, ZIP, DOCX, etc.)',
      'auto_media' => 'Auto-track HTML5 video & audio playback',
      'auto_outbound' => 'Auto-track outbound link clicks',
      'auto_forms' => 'Auto-track form submissions (Drupal webforms, contact forms)',
      'auto_scroll' => 'Auto-track scroll depth (25%, 50%, 75%, 100%)',
      'auto_time_on_page' => 'Auto-track active engaged time on page',
      'auto_time_to_interaction' => 'Auto-track time to first user interaction',
      'auto_video_progress' => 'Auto-track video watch milestones',
      'auto_form_field_timing' => 'Auto-track form field timing',
    ] as $field => $label) {
      $form['interactions'][$field] = [
        '#type' => 'checkbox',
        '#title' => $this->t($label),
        '#default_value' => (bool) ($config->get($field) ?? false),
      ];
    }

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

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $hybrid = (bool) $form_state->getValue('hybrid_enabled');

    $this->config('wsc_analytics.settings')
      ->set('tracking_active', (bool) $form_state->getValue('tracking_active'))
      ->set('tracking_mode', $form_state->getValue('tracking_mode'))
      ->set('exclude_admins', (bool) $form_state->getValue('exclude_admins'))
      ->set('hybrid_enabled', $hybrid)
      ->set('gateway_url', $form_state->getValue('gateway_url'))
      ->set('industry_vertical', $form_state->getValue('industry_vertical'))
      ->set('llms_txt_enabled', (bool) $form_state->getValue('llms_txt_enabled'))
      ->set('llms_txt_summary', (string) $form_state->getValue('llms_txt_summary'))
      ->set('indexnow_enabled', (bool) $form_state->getValue('indexnow_enabled'))
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

    if ($hybrid) {
      $this->core->getProjectCredentials(true);
    }

    parent::submitForm($form, $form_state);
  }
}
