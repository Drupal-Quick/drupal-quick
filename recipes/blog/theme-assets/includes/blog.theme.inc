<?php

/**
 * @file
 * Blog recipe — theme preprocess callbacks.
 *
 * Injected into the custom theme by dq:scaffold when the blog recipe is
 * applied. Uses STARTERKIT_add_preprocessor() to register closures rather
 * than defining Drupal hook functions directly, which would conflict if
 * another recipe also registers for the same hook.
 *
 * dq:scaffold replaces STARTERKIT with the real theme machine name when
 * copying this file, so the calls below resolve to e.g.
 * my_theme_add_preprocessor() — matching the helper in my_theme.theme.
 */

STARTERKIT_add_preprocessor('node__article', function (array &$variables): void {
  // Make Keywords available as a flat list of term label strings so
  // node--article.html.twig can render them without navigating entity refs.
  $node = $variables['node'];
  if ($node->hasField('field_keywords') && !$node->get('field_keywords')->isEmpty()) {
    $variables['keywords'] = array_map(
      static fn($item) => $item->entity->label(),
      iterator_to_array($node->get('field_keywords'))
    );
  }
  else {
    $variables['keywords'] = [];
  }
});
