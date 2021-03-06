<?php

namespace Drupal\search_and_replace\Plugin\Scanner;

use Drupal\scanner\Plugin\Scanner\Node;

class SarNode extends Node {
  public function search($field, $values) {
    $title_collect = [];
    // $field will be string composed of entity type, bundle name, and field
    // name delimited by ':' characters.
    list($entityType, $bundle, $fieldname) = explode(':', $field);

    $query = \Drupal::entityQuery($entityType);
    $query->condition('type', $bundle, '=');
    if ($values['published']) {
      $query->condition('status', 1);
    }
    $conditionVals = parent::buildCondition($values['search'], $values['mode'], $values['wholeword'], $values['regex'], $values['preceded'], $values['followed']);
    if ($values['language'] !== 'all') {
      $query->condition('langcode', $values['language'], '=');
      $query->condition($fieldname, $conditionVals['condition'], $conditionVals['operator'], $values['language']);
    }
    else {
      $query->condition($fieldname, $conditionVals['condition'], $conditionVals['operator']);
    }

    $entities = $query->execute();
    // Iterate over matched entities (nodes) to extract information that will
    // be rendered in the results.
    foreach ($entities as $key => $id) {
      $node = \Drupal\node\Entity\Node::load($id);
      $type = $node->getType();
      $nodeField = $node->get($fieldname);
      $fieldType = $nodeField->getFieldDefinition()->getType();
      if (in_array($fieldType, ['text_with_summary','text','text_long'])) {
        $fieldValue = $nodeField->getValue()[0];
        $title_collect[$id]['title'] = $node->getTitle();
        $title_collect[$id]['lang'] = $node->language()->getId();
        // Find all instances of the term we're looking for.
        preg_match_all($conditionVals['phpRegex'], $fieldValue['value'], $matches,PREG_OFFSET_CAPTURE);
        $newValues = [];
        // Build an array of strings which are displayed in the results.
        foreach ($matches[0] as $k => $v) {
          // The offset of the matched term(s) in the field's text.
          $start = $v[1];
          if ($values['preceded'] !== '') {
            // Bolding won't work if starting position is in the middle of a
            // word (non-word bounded searches), therefore move the start
            // position back as many character as there are in the 'preceded'
            // text
            $start -= strlen($values['preceded']);
          }
          // Extract part of the text which include the search term plus six
          // "words" following it. After finding the string, bold the search
          // term.
          $replaced = preg_replace($conditionVals['phpRegex'], "<strong>$v[0]</strong>", preg_split("/\s+/", substr($fieldValue['value'], $start), 6));
          if (count($replaced) > 1) {
            // The final index contains the remainder of the text, which we
            // don't care about so we discard it.
            array_pop($replaced);
          }
          $newValues[] = implode(' ', $replaced);
        }
        $title_collect[$id]['field'] = $newValues;
      }
      elseif ($fieldType == 'string') {
        $title_collect[$id]['title'] = $node->getTitle();
        preg_match($conditionVals['phpRegex'], $nodeField->getString(), $matches, PREG_OFFSET_CAPTURE);
        $match = $matches[0][0];
        $replaced = preg_replace($conditionVals['phpRegex'], "<strong>$match</strong>", $nodeField->getString());
        $title_collect[$id]['field'] = [$replaced];
      }
    }
    return $title_collect;
  }

}
