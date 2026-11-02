const fs = require('node:fs')

const info = fs.readFileSync('appinfo/info.xml', 'utf8').match(/<version>([^<]+)<\/version>/)?.[1]
const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8')).version
const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'))
const changelog = fs.readFileSync('CHANGELOG.md', 'utf8')
const latest = changelog.match(/^## \[([0-9][^\]]*)\]/m)?.[1]
const unreleased = changelog.split(/^## \[Unreleased\]\s*$/m)[1]?.split(/^## /m)[0] ?? ''
const prerelease = /^\d+\.\d+\.\d+-[0-9A-Za-z.-]+$/.test(info ?? '')
const logMatches = latest === info || (prerelease && unreleased.includes(info))

console.log(`info.xml=${info} package.json=${pkg} package-lock.json=${lock.version} CHANGELOG=${logMatches ? info : latest}`)
if (!info || info !== pkg || info !== lock.version || info !== lock.packages[''].version || !logMatches) {
	console.error('Version mismatch (prereleases must be documented under Unreleased or have their own changelog heading)')
	process.exit(1)
}
