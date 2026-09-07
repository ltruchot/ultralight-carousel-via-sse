# Listing assets for wordpress.org

What the plugin directory shows around the plugin: the icon and the banner.
They are not part of the plugin and never ship in the ZIP (`.distignore`
excludes this folder). They go into the `assets/` folder of the SVN
repository, next to `trunk/` and `tags/`, once the plugin is approved:

```
svn co https://plugins.svn.wordpress.org/ultralight-carousel-via-sse
cp .wordpress-org/icon* .wordpress-org/banner* ultralight-carousel-via-sse/assets/
cd ultralight-carousel-via-sse && svn add assets/* && svn ci -m "Listing assets"
```

| File | Where it shows |
|---|---|
| `icon.svg` | The plugin's icon, animated: the next photograph fades in over the first every few seconds. The directory prefers the SVG when it is there. |
| `icon-128x128.png`, `icon-256x256.png` | The still fallback, required alongside the SVG. |
| `banner-772x250.png`, `banner-1544x500.png` | The header of the plugin page, standard and retina. |

Screenshots (`screenshot-1.png`, ...) are not made yet. When they are, each
needs a caption in a `== Screenshots ==` section of `readme.txt`.

The PNG files are rendered from `icon.svg` and from a small HTML page with
Playwright; there is no source file to keep besides the SVG.
