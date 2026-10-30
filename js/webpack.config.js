const config = require('flarum-webpack-config');

const NS = 'ernestdefoe-vantage';

/*
 * 🚨 Lazy chunks need ids no other extension can have.
 *
 * Flarum's registry finds a chunk's URL by its id alone, and every extension's
 * chunks report to one shared chunk-loading array. Webpack's default ids are
 * 3-digit numbers (the leaderboard was chunk 386), so two extensions can share
 * one: the wrong file is fetched, or the chunk counts as already loaded, and
 * the page dies only on forums running both. So: ids prefixed with our
 * extension id, and our own chunk-loading global.
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

module.exports = (...args) => {
  let base = config();
  if (typeof base === 'function') base = base(...args);

  base.optimization = { ...base.optimization, chunkIds: false };
  base.output = { ...base.output, chunkLoadingGlobal: 'webpackChunk_ernestdefoe_vantage' };
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds()];

  return base;
};
