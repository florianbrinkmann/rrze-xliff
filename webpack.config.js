const defaultConfig = require("./node_modules/@wordpress/scripts/config/webpack.config");
const path = require( 'path' );

module.exports = {
    ...defaultConfig,
    entry: {
        'block-editor-functions': [path.resolve(process.cwd(), 'assets/src/js', 'block-editor-functions.js'), path.resolve(process.cwd(), 'assets/src/scss', 'classic-editor.scss')],
        'classic-editor-functions': path.resolve(process.cwd(), 'assets/src/js', 'classic-editor-functions.js'),
        'bulk-export-functions': path.resolve(process.cwd(), 'assets/src/js', 'bulk-export-functions.js'),
        'nestedpages-bulk-export-functions': path.resolve(process.cwd(), 'assets/src/js', 'nestedpages-bulk-export-functions.js'),
    },
    output: {
        filename: 'dist/js/[name].js',
        path: path.resolve( process.cwd(), 'assets' ),
    },
    module: {
		rules: [
			/**
			 * Running Babel on JS files.
			 */
			...defaultConfig.module.rules,
			{
				test: /\.scss$/,
				use: [
					{
						loader: 'file-loader',
						options: {
							name: 'dist/css/[name].css',
						}
					},
					{
						loader: 'extract-loader'
					},
					{
						loader: 'css-loader?-url'
					},
					{
						loader: 'postcss-loader'
					},
					{
						loader: 'sass-loader'
					}
				]
			}
		]
	}
};
