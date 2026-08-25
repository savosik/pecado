/**
 * Дата в виде `ГГГГ-ММ-ДД` по местному календарю.
 *
 * Не `toISOString()`: он переводит время в UTC, и восточнее Гринвича полночь
 * местного дня уезжает во вчера. В Тюмени (UTC+5) «конец месяца» так
 * превращался в 30 августа вместо 31-го, а «сегодня» после семи вечера — во
 * вчерашний день.
 */
export const localDate = (date = new Date()) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

export default localDate;
