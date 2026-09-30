# Game artwork and galaxy map

Artwork generated using the built-in image-generation tool. WebP files preserve the source alpha channels and are served locally from `public/assets/art/`. The six subject images are transparent; the nebula is opaque. No external image service is needed at runtime.

## Prompt set

### ship-balanced

Use case: stylized-concept. Asset type: production game artwork for BlackNova Traders, a dark navy space trading browser game. Create one original balanced-class explorer spaceship: compact broad armored central hull, two modest side engines, believable utilitarian silver gunmetal plating, subtle cyan navigation lights, three-quarter dorsal perspective, nose pointing upper left. Refined cinematic painted 3D concept art, crisp readable silhouette at small UI sizes, restrained surface detail. Entire ship centered with generous clear margins, no cropping, no other ships, no scene, no floor or cast shadow, no text, logos, border or watermark. Transparent background, square composition. Cohesive art direction: cold starlight from upper left, faint warm rimlight, worn industrial science fiction.

### ship-scout

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. One original fast scout spaceship, slender needle nose, swept compact wings, twin small engines, silver gunmetal, entire ship centered in three-quarter dorsal view with nose pointing lower left, generous transparent margins. No background, no other objects, no floor, no shadow. Square.

### ship-merchant

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. One original bulky merchant cargo spaceship, robust tug-like spine with six modular ochre cargo containers, stout engine pods, dark gunmetal, entire ship centered in three-quarter dorsal view with nose pointing lower left, generous transparent margins. No background, no other objects, no floor, no shadow. Square.

### ship-warship

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. One original heavy warship spaceship, angular long armored destroyer, broad layered prow, integrated recessed gun batteries, deep charcoal plating with small muted red markings and cyan engines. Entire ship centered in three-quarter dorsal view nose pointing lower left, generous transparent margins. No background, no other objects, no floor, no shadow. Square.

### planet-terran

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. One beautiful alien habitable planet globe from orbit, teal oceans, rugged ochre continents, swirling white cloud systems, thin luminous cyan atmosphere. Entire circular sphere fully visible with generous margins, daylight upper left and night terminator lower right. No moons, no ships, no stars, isolated on transparent background. Square.

### station-orbital

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. One original large orbital space station, elegant industrial circular habitat ring around a central docking spindle, radial spokes, tiny amber habitation windows and cyan docking lights, silver gunmetal metal, three-quarter perspective, entire structure visible with generous margins. No ships, no planet, no stars, isolated on transparent background. Square.

### sector-nebula

Use case: stylized-concept. Production artwork for BlackNova Traders dark navy browser game. Refined cinematic painted 3D science-fiction concept art, believable worn industrial materials, crisp readable silhouette, cold light upper left with faint warm rimlight, subtle cyan emissive lights. No text, logos, borders, UI, or watermark. Wide panoramic deep-space sector environment, subtle vast blue-teal and violet nebula wisps along outer edges, fine sparse distant stars, near-black navy center leaving quiet negative space for game content, distant soft amber glow at far right. Premium cinematic astronomical matte painting, restrained contrast, no foreground subjects, no planets, no ships, no station, no text. Landscape 3:2.

## Integration

Ship portraits appear on the status page and sector scene; planet art appears on planet detail and management pages and populated sector scenes; the station appears at ports and starbase sectors. The nebula supports three deterministic sector palettes. A static CSS starfield supplies the site backdrop, with procedural stars in the map. Art depicts sector types rather than exact planet geology or station models.

The authenticated `/galaxy` page charts actual database sectors and directed links using deterministic schematic coordinates, not physical distances. Select with pointer or sector-number search, pan with drag or arrow keys, zoom with buttons or wheel, and use Home/My sector to return. All links is optional to keep large galaxies legible. Search and outgoing-link movement remain available without JavaScript. Travel posts to the existing CSRF-protected movement route; the map exposes only sector names, IDs, port types, starbase flags, and topology, never private ship/planet inventories or defenses.

The artwork and map need no new database columns. This change also fixes Skills on fresh installations by including skill columns in the base schema. Existing installations missing those columns should run `database/migrations/add_skills.sql` with `psql -v ON_ERROR_STOP=1 --single-transaction`; it preserves existing skill levels and points. Deploy `public/assets/` with the PHP changes. The map does not animate continuously and requires no frontend build or external library. Tests: `node --test tests/galaxy.test.js`; PostgreSQL-backed suite: `python3 -m unittest discover -s tests -v` with a disposable server and PGHOST/PGPORT/PGUSER configured.
