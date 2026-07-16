<?php /* خلفية زخرفية للهيرو: كرة أرضية بمسارات شحن — خطّية، شفافة، خلف المحتوى.
        تُضمَّن داخل .hero-main في صفحتَي العرض والإدارة. aria-hidden لأنها زخرفة بحتة. */ ?>
<div class="hero-bg" aria-hidden="true">
  <svg viewBox="0 0 240 200" fill="none" xmlns="http://www.w3.org/2000/svg">
    <!-- الكرة الأرضية -->
    <g stroke="#fff" stroke-width="2" stroke-linecap="round">
      <circle cx="96" cy="100" r="60"/>
      <ellipse cx="96" cy="100" rx="60" ry="23"/>
      <ellipse cx="96" cy="100" rx="25" ry="60"/>
      <ellipse cx="96" cy="100" rx="46" ry="60" opacity=".55"/>
      <ellipse cx="96" cy="72"  rx="50" ry="13" opacity=".5"/>
      <ellipse cx="96" cy="128" rx="50" ry="13" opacity=".5"/>
    </g>
    <!-- مدار شحن حول الأرض -->
    <ellipse cx="112" cy="100" rx="104" ry="46" stroke="#fff" stroke-width="2"
             stroke-dasharray="4 8" transform="rotate(-24 112 100)" opacity=".75"/>
    <!-- طيّارة على المدار -->
    <path transform="rotate(-24 200 62) translate(190 54)" fill="#fff"
          d="M2 6 L16 4 L20 0 L22 1 L20 5 L26 5 L28 3 L30 4 L28 8 L30 12 L28 13 L26 11 L20 11 L22 15 L20 16 L16 12 L2 10 Z"/>
    <!-- سفينة حاويات على المدار -->
    <g transform="rotate(-24 26 132) translate(12 122)" fill="#fff">
      <rect x="10" y="0"  width="7" height="7"/>
      <rect x="18" y="0"  width="7" height="7"/>
      <rect x="14" y="-8" width="7" height="7"/>
      <path d="M2 9 H30 L26 18 H6 Z"/>
    </g>
  </svg>
</div>
