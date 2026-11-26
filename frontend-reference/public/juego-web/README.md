# Juego EasyLunch Rush (Unity WebGL)

Build WebGL del juego que se muestra en la página `/juego` dentro de un iframe.

- `Build/` y `TemplateData/` salen tal cual de Unity (Build → WebGL, Gzip + Decompression Fallback,
  por eso no hace falta configurar headers en el servidor ni en `next.config`).
- `index.html` **está modificado a mano** para que el juego llene toda la ventana del iframe:
  - un bloque `<style>` después de `style.css`: html/body sin margen y al 100% de alto,
    `#unity-container` fijo ocupando todo (sin el `translate` de `.unity-desktop`),
    canvas al 100% × 100% y `#unity-footer` oculto;
  - en el script, el canvas de escritorio pasa de `960px` × `600px` a `100%` × `100%`.

**Si se vuelve a exportar el build desde Unity, hay que repetir este ajuste** en el nuevo
`index.html` (o copiar solo `Build/` y conservar este `index.html` si los nombres de archivo no cambian).
