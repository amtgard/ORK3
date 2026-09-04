<?php

declare(strict_types=1);

/**
 * The scroll orders — one entry per certificate design.
 *
 * Generated from Arcanyst 2.1.3's reference/award-definitions.json (by Valory Fox;
 * flavour text credited there to Kisa / Shelly Alexandra — see NOTICE). Resource
 * paths re-rooted from ./resources/ash/ to scrolls/, resolved against HTTP_ASSETS.
 *
 * Arcanyst's four archery order ids (hunter, bowyer, marksman, sharpshooter) shared
 * one art directory and one fabricated ork_id, differing only in the title they
 * fabricated as "Order of the " + id. In the ORK that word is data, so the three
 * alias entries are dropped: hunter.tpl serves them all and the title comes from
 * ork_kingdomaward.name.
 *
 * Two values Arcanyst kept in its Award constructor rather than this data are
 * applied by Scroll::Orders(): a default rank range of 0-10 (only warrior overrides
 * it, hence the nulls below), and a default `resources` set of flourishes that
 * partial overrides MERGE over rather than replace.
 *
 * Returns an array; no side effects. This directory is not scanned by startup.php
 * (its scandir of system/lib/ork3 is flat), so nothing here is auto-instantiated.
 */

return array(
  'battle' =>
  array(
    'id' => 'battle',
    'award_id' => 243,
    'ranks' => null,
    'variants' =>
    array(
      0 => 'arcane',
      1 => 'martial',
    ),
    'primary' => 'hsl(0, 57%, 61%)',
    'secondary' => 'hsl(30, 55%, 80%)',
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect your exceptional abilities on the battlefield',
      1 => 'May you continue to impress on the battlefield.',
      2 => 'You never hesitate to enter the fray.',
      3 => 'May your blade ever sing.',
      4 => 'To reflect your tireless energy within the fray.',
      5 => 'Not with a whisper, but with a roar.',
      6 => 'Your prowess in battle is admirable.',
      7 => 'Songs will be written of your talents in battle.',
      8 => 'You answer the call to battle with unparalleled dominance.',
      9 => 'You claim the respect of opponents and applause of the world.',
      10 => 'Tales of your valorous battles echo through the ages.',
    ),
  ),
  'crown' =>
  array(
    'id' => 'crown',
    'award_id' => 239,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect your passion and commitment for Amtgard and the community',
      1 => 'To reflect your honorable service to the community.',
      2 => 'This reflects your initiative and achievements as a leader.',
      3 => 'Your leadership is an inspiration to others.',
      4 => 'To reflect your authentic leadership and dedicated service',
      5 => 'To reflect your ability to motivate and inspire others through service to the crown.',
      6 => 'Your service to the crown is exemplary.',
      7 => 'You\'ve proven yourself a reliable and dedicated leader within our community.',
      8 => 'To reflect your engagement and effort in leading our community',
      9 => 'You\'ve earned the eternal respect and admiration of your subjects.',
      10 => 'Your dedication is unwavering and service exemplary, your leadership makes Amtgard the community it is.',
    ),
  ),
  'dragon' =>
  array(
    'id' => 'dragon',
    'award_id' => 25,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => 'hsl(114, 57%, 37%)',
    'secondary' => 'hsl(41, 93%, 55%)',
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect your impressive and continued artistic accomplishments.',
      1 => 'To reflect your entry into the Amtgard arts, we hope this is only the beginning of your journey.',
      2 => 'To reflect your further accomplishments in the arts of Amtgard.',
      3 => 'To reflect your continuing and developing accomplishments in the arts.',
      4 => 'To reflect your persistent and outstanding artistic creations.',
      5 => 'To reflect your impressive and continued artistic accomplishments.',
      6 => 'Your dedication and hard work has shone through in all of your creations.',
      7 => 'Your skills in the arts are unquestionable, we can only hope to see more in the future.',
      8 => 'Your expertise and artistry is truly extraordinary.',
      9 => 'The arts are your domain.',
      10 => 'May your zeal continue weaving beauty into the world.',
    ),
  ),
  'flame' =>
  array(
    'id' => 'flame',
    'award_id' => 34,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'Your collective accomplishments set a standard for Amtgard culture.',
      1 => 'Your collective accomplishments set a standard for Amtgard culture.',
      2 => 'Your collective accomplishments set a standard for Amtgard culture.',
      3 => 'Your collective accomplishments set a standard for Amtgard culture.',
      4 => 'Your collective accomplishments set a standard for Amtgard culture.',
      5 => 'Your collective accomplishments set a standard for Amtgard culture.',
      6 => 'Your collective accomplishments set a standard for Amtgard culture.',
      7 => 'Your collective accomplishments set a standard for Amtgard culture.',
      8 => 'Your collective accomplishments set a standard for Amtgard culture.',
      9 => 'Your collective accomplishments set a standard for Amtgard culture.',
      10 => 'Your collective accomplishments set a standard for Amtgard culture.',
    ),
  ),
  'garber' =>
  array(
    'id' => 'garber',
    'award_id' => 26,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect the extraordinary effort put into your craft.',
      1 => 'This reflects your entry into the art of garbing, we hope this is only the beginning of your journey.',
      2 => 'To reflect your further accomplishments in the art of garbing.',
      3 => 'The path of creation is fraught with setbacks, but you persevere.',
      4 => 'May you continue to delight others with your talents.',
      5 => 'You transmute fabric to garb with skill and tireless effort.',
      6 => 'Each stitch with your needle brings joy to others.',
      7 => 'To reflect your sincere and renowned craft.',
      8 => 'Your needle carries not just thread, but wonder and joy.',
      9 => 'You bring a spark of magic to each and every bolt of cloth you touch.',
      10 => 'Your unique and magical creations come from countless hours of work and practice.',
    ),
  ),
  'griffon' =>
  array(
    'id' => 'griffon',
    'award_id' => 33,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => 'hsl(35, 29%, 55%)',
    'secondary' => 'hsl(34, 72%, 78%)',
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect your unwavering honor upon the battlefield',
      1 => 'This reflects your integrity as a combatant.',
      2 => 'Even in battle, you take care of those around you.',
      3 => 'You\'ve shown great honour as a fighter and friend.',
      4 => 'This reflect your magnanimity and uprightness upon the field of battle.',
      5 => 'This reflects your consistent and exemplary conduct.',
      6 => 'You conduct yourself with dignity and honor on the battlefield',
      7 => 'To pay tribute to your resolute honor in battle.',
      8 => 'You\'re an exceptional fighter, both in skill and in compassion.',
      9 => 'You\'re an examplar in honour, respect, and care for your fellow players.',
      10 => 'In victory or defeat, to enemy or ally, your honour on the field of battle is second to none.',
    ),
  ),
  'jovious' =>
  array(
    'id' => 'jovious',
    'award_id' => 28,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
      'flourish_top' => 'scrolls/jovious/ribbon-top.png',
      'flourish_right' => 'scrolls/jovious/ribbon-right.png',
      'flourish_bottom' => 'scrolls/jovious/ribbon-bottom.png',
    ),
    'flavour' =>
    array(
      0 => 'Your presence lifts the spirits of those around you.',
      1 => 'This award represents your commendable kindness and positivity.',
      2 => 'May you continue to bring joy and positivity to our community.',
      3 => 'May you continue to bring smiles to all those around you.',
      4 => 'The compassionate and selfless will outlast the bitter and cold.',
      5 => 'Your power to lift others up is stronger than the mightiest blade.',
      6 => 'You go above and beyond in making sure all feel welcome and appreciated.',
      7 => 'Your continual kindness has helped cultivate a positive environment for all those in Amtgard.',
      8 => 'Your presence is synonymous with happiness and comfort, and has helped countless in our community.',
      9 => 'Your kindness, passion and positivity all have a profound impact on all those around you.',
      10 => 'You are a shining beacon of compassion and kindness, igniting a flame of joy even in the darkest nights. You represent the best of Amtgard.',
    ),
  ),
  'lion' =>
  array(
    'id' => 'lion',
    'award_id' => 23,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To bring recognition to your willingness to serve.',
      1 => 'To put others before oneself is the true show of a leader.',
      2 => 'To reflect your service and generosity to those around you.',
      3 => 'Your selflessness and dedication are reflections of your character.',
      4 => 'Your actions inspire and guide others through your actions to serve.',
      5 => 'May you continue to lead by example through service.',
      6 => 'To reflect on your consistent and continued service to Amtgard.',
      7 => 'To reflect your exemplary leadership beyond the call of duty.',
      8 => 'You lead by example and show service through your actions. No matter where you are, you strive to help.',
      9 => 'The faith others have in your decisions and ability speaks to your astounding dedication and capability as a leader.',
      10 => 'Day or night, rain or shine; through your tireless dedication, you are the tip of the spear in moving Amtgard into its future.',
    ),
  ),
  'hunter' =>
  array(
    'id' => 'hunter',
    // 6969 is Arcanyst's invention, not an ORK award id — there is no base archery
    // ladder award to point at. Real archery awards hang off award_id 0, 94 (Custom
    // Award) and 131 under a dozen different kingdomaward names. Until a real base id
    // exists, OrderForAwardId() simply never matches this order, so no Print control
    // is offered for archery — the safe failure. See "Deferred" in
    // docs/superpowers/plans/2026-09-03-scrollery.md.
    'award_id' => 6969,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'May your arrows forever strike true.',
      1 => 'May your arrows forever strike true.',
      2 => 'May your arrows forever strike true.',
      3 => 'May your arrows forever strike true.',
      4 => 'May your arrows forever strike true.',
      5 => 'May your arrows forever strike true.',
      6 => 'May your arrows forever strike true.',
      7 => 'May your arrows forever strike true.',
      8 => 'May your arrows forever strike true.',
      9 => 'May your arrows forever strike true.',
      10 => 'May your arrows forever strike true.',
    ),
  ),
  'hydra' =>
  array(
    'id' => 'hydra',
    'award_id' => 32,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'You\'ve proven yourself up to the task of rulership.',
      1 => 'You\'ve proven yourself up to the task of rulership.',
      2 => 'You\'ve proven yourself up to the task of rulership.',
      3 => 'Your accomplishments demonstrate the qualities of a leader.',
      4 => 'Your accomplishments demonstrate the qualities of a leader.',
      5 => 'You\'ve demonstrated once again that an office bestowed upon you is well placed.',
      6 => 'You\'ve demonstrated once again that an office bestowed upon you is well placed.',
      7 => 'Time and again you\'ve demonstrated to others the skill and dedication needed for governance.',
      8 => 'Time and again you\'ve demonstrated to others the skill and dedication needed for governance.',
      9 => 'Your worthiness to lead is indisputable; a shining beacon for those of Amtgard to emulate.',
      10 => 'Your worthiness to lead is indisputable; a shining beacon for those of Amtgard to emulate.',
    ),
  ),
  'mask' =>
  array(
    'id' => 'mask',
    'award_id' => 29,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'You embody your character with enrapturing grace.',
      1 => 'To reflect on your dedication and enjoyment of roleplay.',
      2 => 'You are a shining example of roleplay at its finest.',
      3 => 'For your genuine talent in roleplay.',
      4 => 'To reflect on your exceptional roleplay and genuine dedication to Amtgard.',
      5 => 'To recognize your dedication to the craft of roleplay.',
      6 => 'The mundane disappears when you walk into the game.',
      7 => 'The mask is your friend and you wear it well.',
      8 => 'You bring the spark of wonder and fantasy to the stories you weave with others.',
      9 => 'You carry the heart of story and wonder wherever you go, and bring a magnetic believability to all you embody.',
      10 => 'Tales follow wherever you go.',
    ),
  ),
  'owl' =>
  array(
    'id' => 'owl',
    'award_id' => 24,
    'ranks' => null,
    // Reordered from Arcanyst's data, which listed cyanotype first. Scroll::BuildScroll
    // falls back to variants[0], and cyanotype is a full-bleed dark blue plate with
    // white text and inverted heraldry — heavy on toner and unlike the other fifteen
    // orders. Arcanyst always had a variant picker; the ORK will not until the live
    // overrides land, so the default has to be the sane one. All three remain available.
    'variants' =>
    array(
      0 => 'parchment',
      1 => 'cyanotype',
      2 => 'transparency',
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'The practice and effort of your craft is commendable.',
      1 => 'To reflect your valuable contributions as a craftsman',
      2 => 'To reflect your talents as a craftsman, may you continue your journey in the crafts',
      3 => 'To reflect your continued effort in honing your craft.',
      4 => 'To reflect your outstanding examples of craftsmanship',
      5 => 'You are a shining example of dedicated practice and craftsmanship.',
      6 => 'May you continue to grace us with your talents',
      7 => 'To reflect your sterling talents as a craftsman',
      8 => 'Your crafts are an magnificent example of your talents',
      9 => 'Your craftsmanship is consistently immaculate and an honour to have within Amtgard.',
      10 => 'You are a industrious and talented artisan, having honed your craft and set new standards of quality.',
    ),
  ),
  'rose' =>
  array(
    'id' => 'rose',
    'award_id' => 21,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
      'flourish_top' => 'scrolls/rose/squiggly-top-rose.svg',
      'flourish_right' => 'scrolls/rose/squiggly-right-rose.svg',
      'flourish_bottom' => 'scrolls/rose/squiggly-bottom-rose.svg',
    ),
    'flavour' =>
    array(
      0 => 'Acts of service reflect the heart of the giver',
      1 => 'Even the smallest acts can cause the biggest change',
      2 => 'To perform an act of service helps another, to continue those acts helps a community.',
      3 => 'It is the actions of others that serve their community which inspire us to serve ourselves.',
      4 => 'The strong stand up for themselves. The stronger stand up for others.',
      5 => 'It is only through dedication to others where see the greatest change in ourselves',
      6 => 'To bring others together and build a community takes the acts of a compassionate soul.',
      7 => 'You honor others through your selflessness',
      8 => 'You help build a better community through every act of service',
      9 => 'Through continued acts of service. You inspire others to better a community they love.',
      10 => 'Your dedication and willingness to help the community must never be forgotten.',
    ),
  ),
  'smith' =>
  array(
    'id' => 'smith',
    'award_id' => 22,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'Your efforts and creations allow those in amtgard to enjoy and grow.',
      1 => 'Your efforts and creations allow those in amtgard to enjoy and grow.',
      2 => 'Your efforts and creations allow those in amtgard to enjoy and grow.',
      3 => 'Reflecting your creation of the amazing and fantastical for others to enjoy.',
      4 => 'Reflecting your creation of the amazing and fantastical for others to enjoy.',
      5 => 'Your work stands in the structure of this game\'s history.',
      6 => 'Your work stands in the structure of this game\'s history.',
      7 => 'You\'ve proven your ability to create experiences that are amazing, fantastical, and enlightening for those in our community.',
      8 => 'You\'ve proven your ability to create experiences that are amazing, fantastical, and enlightening for those in our community.',
      9 => 'You create captivating experiences time and time again, sharing knowledge and joy to those lucky to have participated.',
      10 => 'You are the forge for the world we inhabit.',
    ),
  ),
  'warrior' =>
  array(
    'id' => 'warrior',
    'award_id' => 27,
    'ranks' =>
    array(
      'from' => 1,
      'to' => 10,
    ),
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      1 => 'This only represents the start of your journey in becoming a truly feared combatant.',
      2 => 'Your drive to improve and embody a warrior\'s spirit is admirable.',
      3 => 'With practice your blade has found ever increasing alacrity.',
      4 => 'You\'ve proven your might and strength versus your foes.',
      5 => 'Your eyes seek the slightest opening, and your weapon shortly follows.',
      6 => 'Your relentless courage and ferocity leave your foes little time to regroup.',
      7 => 'You fight with celerity and valor, a deadly opponent even against the best.',
      8 => 'Woe be to those who let their guard down against you on the battlefield.',
      9 => 'Your unparalleled martial prowess evokes awe and fear across the lands.',
      10 => 'Neither the flame of your fighting spirit, nor the tales of your martial feats shall ever be extinguished.

To say you\'ve reached the apex of your ability would be to drastically underestimate you.',
    ),
  ),
  'zodiac' =>
  array(
    'id' => 'zodiac',
    'award_id' => 30,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => null,
    'secondary' => null,
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => 'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
      1 => 'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
      2 => 'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
      3 => 'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
      4 => 'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
      5 => 'The spirit you bring to the game << made |MONTH| a month|for whole months>> to remember.',
      6 => '<<You were |MONTH|\'s|You\'re a>> shining star of <<ISSUER>>, and an invaluable member of our community.',
      7 => 'Your service to Amtgard <<during the month of |MONTH| has|has>> kept the game and community together.',
      8 => 'You were the brightest star in <<|MONTH|\'s|the>> night sky, making for an amazing month of Amtgard history.',
      9 => 'You weaved <<|MONTH| into a|a>> month of treasured memories for those of <<ISSUER>>.',
      10 => 'Your efforts have forever written <<|MONTH||new fantastical tales>> into <<ISSUER>>\'s history.',
    ),
  ),
  'custom' =>
  array(
    'id' => 'custom',
    // 0 deliberately: no award maps to the custom scroll BY ID. It is chosen by
    // Scroll::OrderForAwardRow() when an award has no design of its own, so it must
    // never win an id lookup.
    'award_id' => 0,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => 'hsl(35, 29%, 55%)',
    'secondary' => 'hsl(34, 72%, 78%)',
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => '',
      1 => '',
      2 => '',
      3 => '',
      4 => '',
      5 => '',
      6 => '',
      7 => '',
      8 => '',
      9 => '',
      10 => '',
    ),
  ),
);
